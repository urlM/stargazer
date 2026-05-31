<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GitHubRepositorySearchPageResult;
use App\Dto\GitHubRepositoryDTO;
use App\Exception\GitHub\GitHubApiException;
use App\Exception\GitHub\GitHubInvalidResponseException;
use App\Exception\GitHub\GitHubRateLimitException;
use App\Exception\GitHub\GitHubTimeoutException;
use App\Exception\GitHub\GitHubUnavailableException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for interacting with the GitHub Search API.
 */
final class GitHubApiService
{
    private const SEARCH_URL = 'https://api.github.com/search/repositories';
    private const MAX_ATTEMPTS = 3;
    private const RETRY_BACKOFF_MICROSECONDS = [250000, 750000];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $githubToken,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Fetches a single page of PHP repositories from GitHub Search.
     */
    public function searchPhpRepositoriesPage(
        int $perPage = 100,
        int $page = 1,
        ?int $minStars = null,
        ?int $maxStars = null,
    ): GitHubRepositorySearchPageResult
    {
        if ($perPage < 1 || $perPage > 100) {
            throw new GitHubInvalidResponseException('GitHub repository page size must be between 1 and 100.');
        }

        if ($page < 1) {
            throw new GitHubInvalidResponseException('GitHub repository page number must be greater than 0.');
        }

        $startedAt = microtime(true);
        $query = [
            'q' => $this->buildQuery('php', $minStars, $maxStars),
            'sort' => 'stars',
            'order' => 'desc',
            'per_page' => $perPage,
            'page' => $page,
        ];
        $options = [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'Stargazer-App',
            ],
            'timeout' => 10,
            'query' => $query,
        ];

        if ($this->githubToken !== '') {
            $options['headers']['Authorization'] = sprintf('Bearer %s', $this->githubToken);
        }

        $this->logger->info('Fetching PHP repositories page from GitHub.', [
            'page' => $page,
            'per_page' => $perPage,
            'min_stars' => $minStars,
            'max_stars' => $maxStars,
        ]);

        $attempt = 0;

        while (true) {
            try {
                $result = $this->fetchSearchPageAttempt($options, $perPage, $page, $minStars, $maxStars, $startedAt);

                $this->logger->info('Fetched PHP repositories page from GitHub.', [
                    'page' => $page,
                    'per_page' => $perPage,
                    'min_stars' => $minStars,
                    'max_stars' => $maxStars,
                    'count' => count($result->repositories),
                    'total_count' => $result->totalCount,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'retry_count' => $attempt,
                ]);

                return $result;
            } catch (GitHubApiException $exception) {
                if (!$this->shouldRetry($exception, $attempt)) {
                    throw $exception;
                }

                ++$attempt;

                $this->logger->warning('Retrying GitHub API request after retryable failure.', [
                    'page' => $page,
                    'per_page' => $perPage,
                    'min_stars' => $minStars,
                    'max_stars' => $maxStars,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'retry_count' => $attempt,
                    'exception_class' => $exception::class,
                    'status_code' => $exception->getStatusCode(),
                ]);

                $this->backOff($attempt);
            }
        }
    }

    /**
     * Fetches the top-starred PHP repositories from GitHub.
     *
     * @return array<GitHubRepositoryDTO>
     */
    public function fetchTopPhpRepositories(int $limit = 100): array
    {
        return $this->searchPhpRepositoriesPage($limit)->repositories;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function fetchSearchPageAttempt(
        array $options,
        int $perPage,
        int $page,
        ?int $minStars,
        ?int $maxStars,
        float $startedAt,
    ): GitHubRepositorySearchPageResult
    {
        try {
            $response = $this->httpClient->request('GET', self::SEARCH_URL, $options);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $exception = $this->createStatusException($statusCode);
                $this->logger->warning('GitHub API returned an unsuccessful status code.', [
                    'page' => $page,
                    'per_page' => $perPage,
                    'min_stars' => $minStars,
                    'max_stars' => $maxStars,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'status_code' => $statusCode,
                    'retryable' => $exception->isRetryable(),
                ]);

                throw $exception;
            }

            $data = $response->toArray();
        } catch (TransportExceptionInterface $exception) {
            $classifiedException = $this->createTransportException($exception);
            $this->logger->warning('GitHub API transport failure.', [
                'page' => $page,
                'per_page' => $perPage,
                'min_stars' => $minStars,
                'max_stars' => $maxStars,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'exception_class' => $classifiedException::class,
                'retryable' => $classifiedException->isRetryable(),
            ]);

            throw $classifiedException;
        } catch (DecodingExceptionInterface $exception) {
            $this->logger->warning('GitHub API returned invalid JSON.', [
                'page' => $page,
                'per_page' => $perPage,
                'min_stars' => $minStars,
                'max_stars' => $maxStars,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw new GitHubInvalidResponseException('GitHub API returned an invalid JSON response.', previous: $exception);
        }

        if (!isset($data['items']) || !is_array($data['items'])) {
            $this->logger->warning('GitHub API response is missing repository items.', [
                'page' => $page,
                'per_page' => $perPage,
                'min_stars' => $minStars,
                'max_stars' => $maxStars,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw new GitHubInvalidResponseException('GitHub API response did not include a repository items array.');
        }

        $dtos = [];

        foreach ($data['items'] as $item) {
            if (!is_array($item)) {
                throw new GitHubInvalidResponseException('GitHub API response included an invalid repository item.');
            }

            $dtos[] = new GitHubRepositoryDTO(
                (string) $this->requiredField($item, 'id'),
                (string) $this->requiredField($item, 'full_name'),
                (string) $this->requiredField($item, 'html_url'),
                $this->nullableStringField($item, 'description'),
                (int) $this->requiredField($item, 'stargazers_count'),
                $this->dateTimeField($item, 'created_at'),
                $this->dateTimeField($item, 'pushed_at'),
            );
        }

        $rateLimitRemaining = $response->getHeaders(false)['x-ratelimit-remaining'][0] ?? null;

        return new GitHubRepositorySearchPageResult(
            $dtos,
            isset($data['total_count']) && is_numeric($data['total_count'])
                ? (int) $data['total_count']
                : count($dtos),
            $page,
            $perPage,
            $rateLimitRemaining !== null ? (int) $rateLimitRemaining : null,
        );
    }

    private function shouldRetry(GitHubApiException $exception, int $attempt): bool
    {
        return $exception->isRetryable() && $attempt < self::MAX_ATTEMPTS - 1;
    }

    private function backOff(int $attempt): void
    {
        $delay = self::RETRY_BACKOFF_MICROSECONDS[$attempt - 1] ?? self::RETRY_BACKOFF_MICROSECONDS[array_key_last(self::RETRY_BACKOFF_MICROSECONDS)];

        if ($delay > 0) {
            usleep($delay);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function requiredField(array $item, string $field): mixed
    {
        if (!array_key_exists($field, $item) || $item[$field] === null) {
            throw new GitHubInvalidResponseException(sprintf('GitHub API response item is missing required field "%s".', $field));
        }

        return $item[$field];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function nullableStringField(array $item, string $field): ?string
    {
        if (!array_key_exists($field, $item) || $item[$field] === null) {
            return null;
        }

        return (string) $item[$field];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function dateTimeField(array $item, string $field): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable((string) $this->requiredField($item, $field));
        } catch (\Exception $exception) {
            throw new GitHubInvalidResponseException(sprintf('GitHub API response item has invalid datetime field "%s".', $field), previous: $exception);
        }
    }

    private function createStatusException(int $statusCode): GitHubApiException
    {
        if ($statusCode === 403 || $statusCode === 429) {
            return new GitHubRateLimitException(
                sprintf('GitHub API rate limit or access limit returned status code %d.', $statusCode),
                $statusCode,
                true,
            );
        }

        if ($statusCode >= 500) {
            return new GitHubUnavailableException(
                sprintf('GitHub API is temporarily unavailable and returned status code %d.', $statusCode),
                $statusCode,
                true,
            );
        }

        return new GitHubApiException(
            sprintf('GitHub API request failed with status code %d.', $statusCode),
            $statusCode,
        );
    }

    private function createTransportException(TransportExceptionInterface $exception): GitHubApiException
    {
        if (str_contains(strtolower($exception->getMessage()), 'timed out')) {
            return new GitHubTimeoutException('GitHub API request timed out.', retryable: true, previous: $exception);
        }

        return new GitHubUnavailableException('GitHub API request failed before a response was received.', retryable: true, previous: $exception);
    }

    private function buildQuery(string $language, ?int $minStars, ?int $maxStars): string
    {
        $query = sprintf('language:%s', $language);

        if ($minStars !== null && $maxStars !== null) {
            return sprintf('%s stars:%d..%d', $query, $minStars, $maxStars);
        }

        if ($minStars !== null) {
            return sprintf('%s stars:>=%d', $query, $minStars);
        }

        if ($maxStars !== null) {
            return sprintf('%s stars:<=%d', $query, $maxStars);
        }

        return $query;
    }
}
