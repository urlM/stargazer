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
use App\Service\RepositorySyncService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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

        $handler = $this->makeHandler($this->mockHttpClientReturning($response));
        $handler(new SyncRepositoriesMessage(
            'php',
            1,
            'corr-success',
            '2026-05-30T22:30:00+00:00',
            'manual',
        ));

        $storedRepository = $this->repositories->find('458058');
        $syncLog = $this->syncLogs->findOneByCorrelationId('corr-success');

        self::assertInstanceOf(Repository::class, $storedRepository);
        self::assertInstanceOf(SyncLog::class, $syncLog);
        self::assertSame('success', $syncLog->getStatus());
        self::assertSame('manual', $syncLog->getTriggeredBy());
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
            ));
        } finally {
            $syncLog = $this->syncLogs->findOneByCorrelationId('corr-failed');

            self::assertInstanceOf(SyncLog::class, $syncLog);
            self::assertSame('failed', $syncLog->getStatus());
            self::assertSame('manual', $syncLog->getTriggeredBy());
            self::assertSame(0, $syncLog->getRetryCount());
            self::assertNotNull($syncLog->getError());
            self::assertNotNull($syncLog->getDurationMs());
            self::assertSame(0, $this->repositories->count([]));
        }
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

    private function makeHandler(HttpClientInterface $httpClient): SyncRepositoriesHandler
    {
        return new SyncRepositoriesHandler(
            new GitHubApiService($httpClient, ''),
            new RepositorySyncService($this->entityManager, $this->repositories),
            $this->syncLogs,
            new NullLogger(),
        );
    }
}
