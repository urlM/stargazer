<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Repository;
use App\Entity\SyncLog;
use App\Exception\GitHub\GitHubUnavailableException;
use App\Message\SyncRepositoriesMessage;
use App\MessageHandler\SyncRepositoriesHandler;
use App\Repository\RepositoryRepository;
use App\Repository\SyncLogRepository;
use App\Service\GitHubApiService;
use App\Service\RepositorySyncOptions;
use App\Service\RepositorySyncService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SyncRepositoriesHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private RepositoryRepository $repositories;
    private SyncLogRepository $syncLogs;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repositories = $container->get(RepositoryRepository::class);
        $this->syncLogs = $container->get(SyncLogRepository::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        parent::ensureKernelShutdown();
        unset($this->entityManager, $this->repositories, $this->syncLogs);
    }

    public function testHandlerCreatesSuccessfulSyncLogAndPersistsRepositories(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'total_count' => 1,
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
        $response->method('getHeaders')->with(false)->willReturn([
            'x-ratelimit-remaining' => ['4999'],
        ]);

        $handler = $this->makeHandler($this->mockHttpClientReturning($response));
        $handler(new SyncRepositoriesMessage(
            'php',
            1,
            'corr-success',
            '2026-05-30T22:30:00+00:00',
            'manual',
            'all',
        ));

        $storedRepository = $this->repositories->find('458058');
        $syncLog = $this->syncLogs->findOneByCorrelationId('corr-success');

        self::assertInstanceOf(Repository::class, $storedRepository);
        self::assertInstanceOf(SyncLog::class, $syncLog);
        self::assertSame('success', $syncLog->getStatus());
        self::assertSame('manual', $syncLog->getTriggeredBy());
        self::assertSame('all', $syncLog->getStarRangeKey());
        self::assertSame(1, $syncLog->getMaxRepositories());
        self::assertSame(1, $syncLog->getSyncedCount());
        self::assertSame(0, $syncLog->getRetryCount());
        self::assertNull($syncLog->getError());
        self::assertNotNull($syncLog->getDurationMs());
    }

    public function testHandlerCreatesFailedSyncLogWhenGitHubFails(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $response->expects(self::never())->method('toArray');

        $handler = $this->makeHandler($this->mockHttpClientReturning($response, 3));

        $this->expectException(GitHubUnavailableException::class);

        try {
            $handler(new SyncRepositoriesMessage(
                'php',
                1,
                'corr-failed',
                '2026-05-30T22:31:00+00:00',
                'manual',
                'all',
            ));
        } finally {
            $syncLog = $this->syncLogs->findOneByCorrelationId('corr-failed');

            self::assertInstanceOf(SyncLog::class, $syncLog);
            self::assertSame('failed', $syncLog->getStatus());
            self::assertSame('manual', $syncLog->getTriggeredBy());
            self::assertSame('all', $syncLog->getStarRangeKey());
            self::assertSame(1, $syncLog->getMaxRepositories());
            self::assertSame(0, $syncLog->getRetryCount());
            self::assertNotNull($syncLog->getError());
            self::assertNotNull($syncLog->getDurationMs());
            self::assertSame(0, $this->repositories->count([]));
        }
    }

    public function testHandlerDispatchesFollowUpMessageWhenMorePagesRemain(): void
    {
        $items = [];

        for ($index = 1; $index <= 100; ++$index) {
            $items[] = [
                'id' => $index,
                'full_name' => sprintf('vendor/repo-%03d', $index),
                'html_url' => sprintf('https://github.com/vendor/repo-%03d', $index),
                'description' => 'Repository description',
                'stargazers_count' => 1000 - $index,
                'created_at' => '2020-01-01T00:00:00+00:00',
                'pushed_at' => '2026-05-24T11:15:00+00:00',
            ];
        }

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'total_count' => 150,
            'items' => $items,
        ]);
        $response->method('getHeaders')->with(false)->willReturn([
            'x-ratelimit-remaining' => ['4999'],
        ]);

        $dispatchedMessages = [];
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (SyncRepositoriesMessage $message) use (&$dispatchedMessages): bool {
                $dispatchedMessages[] = $message;

                return true;
            }))
            ->willReturnCallback(static fn (SyncRepositoriesMessage $message): Envelope => new Envelope($message));

        $handler = $this->makeHandler($this->mockHttpClientReturning($response), $messageBus);
        $handler(new SyncRepositoriesMessage(
            'php',
            150,
            'corr-follow-up',
            '2026-05-30T22:32:00+00:00',
            'manual',
            '100_999',
            150,
            0,
            [['min' => 100, 'max' => 999, 'page' => 1]],
        ));

        $syncLog = $this->syncLogs->findOneByCorrelationId('corr-follow-up');

        self::assertInstanceOf(SyncLog::class, $syncLog);
        self::assertSame('running', $syncLog->getStatus());
        self::assertSame(100, $syncLog->getSyncedCount());
        self::assertCount(100, $this->repositories->findAll());
        self::assertCount(1, $dispatchedMessages);
        self::assertSame(50, $dispatchedMessages[0]->remainingRepositories);
        self::assertSame(100, $dispatchedMessages[0]->syncedCount);
        self::assertSame([['min' => 100, 'max' => 999, 'page' => 2]], $dispatchedMessages[0]->pendingShards);
    }

    /**
     * @return HttpClientInterface&MockObject
     */
    private function mockHttpClientReturning(ResponseInterface $response, int $expectedRequests = 1): HttpClientInterface
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::exactly($expectedRequests))
            ->method('request')
            ->with('GET', 'https://api.github.com/search/repositories', self::isArray())
            ->willReturn($response);

        return $httpClient;
    }

    private function makeHandler(HttpClientInterface $httpClient, ?MessageBusInterface $messageBus = null): SyncRepositoriesHandler
    {
        if ($messageBus === null) {
            $messageBus = $this->createMock(MessageBusInterface::class);
            $messageBus->expects(self::any())->method('dispatch');
        }

        return new SyncRepositoriesHandler(
            new GitHubApiService($httpClient, ''),
            new RepositorySyncService($this->entityManager, $this->repositories),
            new RepositorySyncOptions(),
            $this->syncLogs,
            $messageBus,
            new NullLogger(),
        );
    }
}
