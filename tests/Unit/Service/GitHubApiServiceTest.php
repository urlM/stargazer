<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Exception\GitHub\GitHubApiException;
use App\Exception\GitHub\GitHubInvalidResponseException;
use App\Exception\GitHub\GitHubRateLimitException;
use App\Exception\GitHub\GitHubTimeoutException;
use App\Exception\GitHub\GitHubUnavailableException;
use App\Service\GitHubApiService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class GitHubApiServiceTest extends TestCase
{
    public function testFetchTopPhpRepositoriesSendsExpectedRequestAndMapsResponse(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects(self::once())
            ->method('toArray')
            ->willReturn([
                'items' => [[
                    'id' => 458058,
                    'full_name' => 'symfony/symfony',
                    'html_url' => 'https://github.com/symfony/symfony',
                    'description' => 'The Symfony PHP framework.',
                    'stargazers_count' => 30418,
                    'created_at' => '2011-01-12T15:38:48+00:00',
                    'pushed_at' => '2026-05-24T11:15:00+00:00',
                ]],
            ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://api.github.com/search/repositories',
                self::callback(static function (array $options): bool {
                    self::assertSame('application/vnd.github.v3+json', $options['headers']['Accept']);
                    self::assertSame('Stargazer-App', $options['headers']['User-Agent']);
                    self::assertSame('Bearer test-token', $options['headers']['Authorization']);

                    self::assertSame('language:php', $options['query']['q']);
                    self::assertSame('stars', $options['query']['sort']);
                    self::assertSame('desc', $options['query']['order']);
                    self::assertSame(25, $options['query']['per_page']);
                    self::assertSame(10, $options['timeout']);

                    return true;
                }),
            )
            ->willReturn($response);

        $service = new GitHubApiService($httpClient, 'test-token');
        $dtos = $service->fetchTopPhpRepositories(25);

        self::assertCount(1, $dtos);
        self::assertSame('458058', $dtos[0]->id);
        self::assertSame('symfony/symfony', $dtos[0]->name);
        self::assertSame('https://github.com/symfony/symfony', $dtos[0]->url);
        self::assertSame('The Symfony PHP framework.', $dtos[0]->description);
        self::assertSame(30418, $dtos[0]->stars);
        self::assertSame('2011-01-12T15:38:48+00:00', $dtos[0]->createdAt->format(DATE_ATOM));
        self::assertSame('2026-05-24T11:15:00+00:00', $dtos[0]->pushedAt->format(DATE_ATOM));
    }

    public function testFetchTopPhpRepositoriesClassifiesRateLimitStatus(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::exactly(3))
            ->method('getStatusCode')
            ->willReturn(429);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::exactly(3))
            ->method('request')
            ->willReturn($response);

        $service = new GitHubApiService($httpClient, '');

        try {
            $service->fetchTopPhpRepositories();
            self::fail('Expected GitHubRateLimitException to be thrown.');
        } catch (GitHubRateLimitException $exception) {
            self::assertSame(429, $exception->getStatusCode());
            self::assertTrue($exception->isRetryable());
        }
    }

    public function testFetchTopPhpRepositoriesClassifiesUnavailableStatus(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::exactly(3))
            ->method('getStatusCode')
            ->willReturn(503);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::exactly(3))
            ->method('request')
            ->willReturn($response);

        $service = new GitHubApiService($httpClient, '');

        try {
            $service->fetchTopPhpRepositories();
            self::fail('Expected GitHubUnavailableException to be thrown.');
        } catch (GitHubUnavailableException $exception) {
            self::assertSame(503, $exception->getStatusCode());
            self::assertTrue($exception->isRetryable());
        }
    }

    public function testFetchTopPhpRepositoriesClassifiesGenericApiStatus(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())
            ->method('getStatusCode')
            ->willReturn(422);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->willReturn($response);

        $service = new GitHubApiService($httpClient, '');

        try {
            $service->fetchTopPhpRepositories();
            self::fail('Expected GitHubApiException to be thrown.');
        } catch (GitHubApiException $exception) {
            self::assertSame(422, $exception->getStatusCode());
            self::assertFalse($exception->isRetryable());
        }
    }

    public function testFetchTopPhpRepositoriesRejectsInvalidLimit(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())
            ->method('request');

        $service = new GitHubApiService($httpClient, '');

        $this->expectException(GitHubInvalidResponseException::class);

        $service->fetchTopPhpRepositories(101);
    }

    public function testFetchTopPhpRepositoriesClassifiesMissingItemsAsInvalidResponse(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects(self::once())
            ->method('toArray')
            ->willReturn(['total_count' => 1]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->willReturn($response);

        $service = new GitHubApiService($httpClient, '');

        $this->expectException(GitHubInvalidResponseException::class);

        $service->fetchTopPhpRepositories();
    }

    public function testFetchTopPhpRepositoriesClassifiesMissingRepositoryFieldAsInvalidResponse(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects(self::once())
            ->method('toArray')
            ->willReturn([
                'items' => [[
                    'id' => 458058,
                    'full_name' => 'symfony/symfony',
                    'html_url' => 'https://github.com/symfony/symfony',
                    'description' => null,
                    'stargazers_count' => 30418,
                    'created_at' => '2011-01-12T15:38:48+00:00',
                ]],
            ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->willReturn($response);

        $service = new GitHubApiService($httpClient, '');

        $this->expectException(GitHubInvalidResponseException::class);

        $service->fetchTopPhpRepositories();
    }

    public function testFetchTopPhpRepositoriesClassifiesTimeoutTransportFailure(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::exactly(3))
            ->method('request')
            ->willThrowException(new class('Operation timed out') extends \RuntimeException implements TransportExceptionInterface {
            });

        $service = new GitHubApiService($httpClient, '');

        try {
            $service->fetchTopPhpRepositories();
            self::fail('Expected GitHubTimeoutException to be thrown.');
        } catch (GitHubTimeoutException $exception) {
            self::assertTrue($exception->isRetryable());
        }
    }

    public function testFetchTopPhpRepositoriesRetriesRetryableStatusThenSucceeds(): void
    {
        $unavailableResponse = $this->createMock(ResponseInterface::class);
        $unavailableResponse->expects(self::once())
            ->method('getStatusCode')
            ->willReturn(503);

        $successResponse = $this->createMock(ResponseInterface::class);
        $successResponse->expects(self::once())
            ->method('getStatusCode')
            ->willReturn(200);
        $successResponse->expects(self::once())
            ->method('toArray')
            ->willReturn([
                'items' => [[
                    'id' => 458058,
                    'full_name' => 'symfony/symfony',
                    'html_url' => 'https://github.com/symfony/symfony',
                    'description' => 'The Symfony PHP framework.',
                    'stargazers_count' => 30418,
                    'created_at' => '2011-01-12T15:38:48+00:00',
                    'pushed_at' => '2026-05-24T11:15:00+00:00',
                ]],
            ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($unavailableResponse, $successResponse);

        $service = new GitHubApiService($httpClient, '');
        $dtos = $service->fetchTopPhpRepositories();

        self::assertCount(1, $dtos);
        self::assertSame('symfony/symfony', $dtos[0]->name);
    }
}
