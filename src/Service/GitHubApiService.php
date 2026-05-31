<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GitHubRepositoryDTO;
use App\Exception\GitHub\GitHubApiException;
use App\Exception\GitHub\GitHubRateLimitException;
use App\Exception\GitHub\GitHubUnavailableException;
use DateTimeImmutable;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for interacting with the GitHub Search API.
 */
final class GitHubApiService
{
    private const SEARCH_URL = 'https://api.github.com/search/repositories';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $githubToken,
    ) {
    }

    /**
     * Fetches the top-starred PHP repositories from GitHub.
     *
     * @return array<GitHubRepositoryDTO>
     */
    public function fetchTopPhpRepositories(int $limit = 100): array
    {
        $options = [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'Stargazer-App',
            ],
            'query' => [
                'q' => 'language:php',
                'sort' => 'stars',
                'order' => 'desc',
                'per_page' => $limit,
            ],
        ];

        if ($this->githubToken !== '') {
            $options['headers']['Authorization'] = sprintf('Bearer %s', $this->githubToken);
        }

        $response = $this->httpClient->request('GET', self::SEARCH_URL, $options);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            throw $this->createStatusException($statusCode);
        }

        $data = $response->toArray();
        $dtos = [];

        foreach ($data['items'] ?? [] as $item) {
            $dtos[] = new GitHubRepositoryDTO(
                (string) $item['id'],
                $item['full_name'],
                $item['html_url'],
                $item['description'],
                (int) $item['stargazers_count'],
                new DateTimeImmutable($item['created_at']),
                new DateTimeImmutable($item['pushed_at']),
            );
        }

        return $dtos;
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
}
