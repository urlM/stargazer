<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\RefreshRepositoriesCommand;
use App\Repository\RepositoryRepository;
use App\Service\GitHubApiService;
use App\Service\RepositorySyncService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RefreshRepositoriesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private RepositoryRepository $repositories;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repositories = $container->get(RepositoryRepository::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        parent::ensureKernelShutdown();
        unset($this->entityManager, $this->repositories);
    }

    public function testExecuteSynchronizesRepositoriesAndReportsSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
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

        $httpClient = $this->mockHttpClientReturning($response);
        $this->swapServices($httpClient);

        $command = static::getContainer()->get(RefreshRepositoriesCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Successfully synchronized 1 repositories', $tester->getDisplay());
        self::assertSame(1, $this->repositories->count([]));
    }

    public function testExecuteReportsFailureWhenGithubApiCallFails(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $response->expects(self::never())->method('toArray');

        $httpClient = $this->mockHttpClientReturning($response);
        $this->swapServices($httpClient);

        $command = static::getContainer()->get(RefreshRepositoriesCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('An error occurred during synchronization', $tester->getDisplay());
        self::assertSame(0, $this->repositories->count([]));
    }

    /**
     * @return HttpClientInterface&MockObject
     */
    private function mockHttpClientReturning(ResponseInterface $response): HttpClientInterface
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.github.com/search/repositories', self::isArray())
            ->willReturn($response);

        return $httpClient;
    }

    private function swapServices(HttpClientInterface $httpClient): void
    {
        $container = static::getContainer();

        $container->set(GitHubApiService::class, new GitHubApiService($httpClient, ''));
        $container->set(
            RepositorySyncService::class,
            new RepositorySyncService($this->entityManager, $this->repositories),
        );
    }
}
