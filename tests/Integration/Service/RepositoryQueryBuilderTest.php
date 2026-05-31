<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Repository;
use App\Service\RepositoryQueryBuilder;
use App\Service\RepositorySyncOptions;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepositoryQueryBuilderTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private RepositoryQueryBuilder $queryBuilder;
    private RepositorySyncOptions $syncOptions;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->queryBuilder = $container->get(RepositoryQueryBuilder::class);
        $this->syncOptions = $container->get(RepositorySyncOptions::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->entityManager->close();
        }

        parent::ensureKernelShutdown();
        unset($this->entityManager, $this->queryBuilder, $this->syncOptions);
    }

    public function testFindRepositoryListItemsUsesFixedTopNScopedDatasetAcrossAlternateSorts(): void
    {
        $this->seedRepositories();

        $scope = $this->syncOptions->resolvedStarRangeScope('5000_9999');

        self::assertSame([
            ['id' => '2', 'name' => 'beta/toolkit', 'stars' => 7500],
            ['id' => '3', 'name' => 'gamma/library', 'stars' => 5200],
        ], $this->queryBuilder->findRepositoryListItems(
            sortBy: 'stars',
            sortDirection: 'DESC',
            limit: 10,
            offset: 0,
            scope: $scope,
            maxRepositories: 2,
        ));

        self::assertSame([
            ['id' => '2', 'name' => 'beta/toolkit', 'stars' => 7500],
            ['id' => '3', 'name' => 'gamma/library', 'stars' => 5200],
        ], $this->queryBuilder->findRepositoryListItems(
            sortBy: 'name',
            sortDirection: 'ASC',
            limit: 10,
            offset: 0,
            scope: $scope,
            maxRepositories: 2,
        ));

        self::assertSame([], $this->queryBuilder->findRepositoryListItems(
            search: 'alpha',
            sortBy: 'name',
            sortDirection: 'ASC',
            limit: 10,
            offset: 0,
            scope: $scope,
            maxRepositories: 2,
        ));

        self::assertSame([], $this->queryBuilder->findRepositoryListItems(
            sortBy: 'stars',
            sortDirection: 'DESC',
            limit: 10,
            offset: 2,
            scope: $scope,
            maxRepositories: 2,
        ));
    }

    public function testCountRepositoriesUsesFixedTopNScopedDatasetForSearchAndTotals(): void
    {
        $this->seedRepositories();

        $scope = $this->syncOptions->resolvedStarRangeScope('5000_9999');

        self::assertSame(3, $this->queryBuilder->countRepositories(scope: $scope));
        self::assertSame(2, $this->queryBuilder->countRepositories(scope: $scope, maxRepositories: 2));
        self::assertSame(0, $this->queryBuilder->countRepositories('alpha', scope: $scope, maxRepositories: 2));
        self::assertSame(1, $this->queryBuilder->countRepositories('gamma', scope: $scope, maxRepositories: 2));
    }

    public function testResolvedScopedRepositoryIdsCanBeReusedAcrossCountAndListing(): void
    {
        $this->seedRepositories();

        $scope = $this->syncOptions->resolvedStarRangeScope('5000_9999');
        $repositoryIds = $this->queryBuilder->resolveScopedRepositoryIds($scope, 2);

        self::assertSame(['2', '3'], $repositoryIds);
        self::assertSame(2, $this->queryBuilder->countRepositories(scope: $scope, maxRepositories: 2, repositoryIds: $repositoryIds));
        self::assertSame([
            ['id' => '2', 'name' => 'beta/toolkit', 'stars' => 7500],
            ['id' => '3', 'name' => 'gamma/library', 'stars' => 5200],
        ], $this->queryBuilder->findRepositoryListItems(
            sortBy: 'stars',
            sortDirection: 'DESC',
            limit: 10,
            offset: 0,
            scope: $scope,
            maxRepositories: 2,
            repositoryIds: $repositoryIds,
        ));
    }

    private function seedRepositories(): void
    {
        $this->entityManager->persist($this->makeRepository('1', 'alpha/project', 12000));
        $this->entityManager->persist($this->makeRepository('2', 'beta/toolkit', 7500));
        $this->entityManager->persist($this->makeRepository('3', 'gamma/library', 5200));
        $this->entityManager->persist($this->makeRepository('5', 'alpha/within-scope', 5100));
        $this->entityManager->persist($this->makeRepository('4', 'delta/helper', 400));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function makeRepository(string $id, string $name, int $stars): Repository
    {
        return new Repository(
            $id,
            $name,
            sprintf('https://github.com/%s', $name),
            sprintf('%s description', $name),
            $stars,
            new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-05-24T12:00:00+00:00'),
            new DateTimeImmutable('2026-05-24T13:00:00+00:00'),
        );
    }
}
