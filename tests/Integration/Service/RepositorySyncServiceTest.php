<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\GitHubRepositoryDTO;
use App\Entity\Repository;
use App\Repository\RepositoryRepository;
use App\Service\RepositorySyncService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepositorySyncServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private RepositoryRepository $repositories;
    private RepositorySyncService $syncService;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repositories = $container->get(RepositoryRepository::class);
        $this->syncService = $container->get(RepositorySyncService::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        parent::ensureKernelShutdown();
        unset($this->entityManager, $this->repositories, $this->syncService);
    }

    public function testSyncCreatesNewRepository(): void
    {
        $dto = new GitHubRepositoryDTO(
            '458058',
            'symfony/symfony',
            'https://github.com/symfony/symfony',
            'The Symfony PHP framework.',
            30418,
            new DateTimeImmutable('2011-01-12T15:38:48+00:00'),
            new DateTimeImmutable('2026-05-24T11:15:00+00:00'),
        );

        $this->syncService->sync([$dto]);
        $this->entityManager->clear();

        $stored = $this->repositories->find('458058');

        self::assertInstanceOf(Repository::class, $stored);
        self::assertSame(1, $this->repositories->count([]));
        self::assertSame('symfony/symfony', $stored->getName());
        self::assertSame(30418, $stored->getStars());
    }

    public function testSyncUpdatesExistingRepositoryWithoutDuplicateAndRefreshesSyncedAt(): void
    {
        $oldSyncedAt = new DateTimeImmutable('2020-01-01T00:00:00+00:00');
        $existing = new Repository(
            '458058',
            'symfony/old',
            'https://github.com/symfony/old',
            'Old description',
            100,
            new DateTimeImmutable('2011-01-12T15:38:48+00:00'),
            new DateTimeImmutable('2026-05-20T11:15:00+00:00'),
            $oldSyncedAt,
        );
        $this->entityManager->persist($existing);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $dto = new GitHubRepositoryDTO(
            '458058',
            'symfony/symfony',
            'https://github.com/symfony/symfony',
            'The Symfony PHP framework.',
            30499,
            new DateTimeImmutable('2011-01-12T15:38:48+00:00'),
            new DateTimeImmutable('2026-05-24T11:15:00+00:00'),
        );

        $this->syncService->sync([$dto]);
        $this->entityManager->clear();

        $updated = $this->repositories->find('458058');

        self::assertInstanceOf(Repository::class, $updated);
        self::assertSame(1, $this->repositories->count([]));
        self::assertSame('symfony/symfony', $updated->getName());
        self::assertSame(30499, $updated->getStars());
        self::assertGreaterThan($oldSyncedAt->getTimestamp(), $updated->getSyncedAt()->getTimestamp());
    }
}
