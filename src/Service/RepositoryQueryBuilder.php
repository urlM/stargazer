<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Repository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Service responsible for building queries for repository listings with filtering and sorting.
 */
final class RepositoryQueryBuilder
{
    private const VALID_SORT_FIELDS = ['stars', 'created_at', 'pushed_at', 'name'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SearchQueryNormalizer $searchQueryNormalizer,
        private readonly LoggerInterface $logger,
        private readonly bool $stargazerTimingEnabled,
        private readonly RepositoryCacheVersionManager $repositoryCacheVersionManager,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Creates a query builder for repositories with optional filtering and sorting.
     *
     * @param string|null $search        Optional search query (searches in name and description).
     * @param string      $sortBy        Field to sort by ('stars', 'created_at', 'pushed_at', 'name').
     * @param string      $sortDirection Sort direction ('ASC' or 'DESC').
     * @param list<string>|null $repositoryIds
     */
    public function createListQueryBuilder(
        ?string $search = null,
        string $sortBy = 'stars',
        string $sortDirection = 'DESC',
        ?ResolvedStarRangeScope $scope = null,
        ?array $repositoryIds = null,
    ): QueryBuilder {
        $qb = $this->createBaseQueryBuilder($search, $scope, $repositoryIds);

        return $this->applySorting($qb, $sortBy, $sortDirection)
            ->select('repository');
    }

    /**
     * Creates a lightweight query builder for repository list rows.
     *
     * @param list<string>|null $repositoryIds
     */
    public function createListItemsQueryBuilder(
        ?string $search = null,
        string $sortBy = 'stars',
        string $sortDirection = 'DESC',
        ?ResolvedStarRangeScope $scope = null,
        ?array $repositoryIds = null,
    ): QueryBuilder {
        $qb = $this->createBaseQueryBuilder($search, $scope, $repositoryIds);

        return $this->applySorting($qb, $sortBy, $sortDirection)
            ->select('repository.id AS id', 'repository.name AS name', 'repository.stars AS stars');
    }

    /**
     * Finds lightweight repository list rows by list criteria.
     *
     * @return list<array{id: string, name: string, stars: int|string}>
     */
    public function findRepositoryListItems(
        ?string $search = null,
        string $sortBy = 'stars',
        string $sortDirection = 'DESC',
        int $limit = 100,
        int $offset = 0,
        ?ResolvedStarRangeScope $scope = null,
        ?int $maxRepositories = null,
        ?array $repositoryIds = null,
    ): array {
        $effectiveLimit = $this->resolveEffectiveLimit($limit, $offset, $scope, $maxRepositories);
        if ($effectiveLimit === 0) {
            return [];
        }

        return $this->createListItemsQueryBuilder(
            $search,
            $sortBy,
            $sortDirection,
            $scope,
            $repositoryIds ?? $this->resolveScopedTopRepositoryIds($scope, $maxRepositories),
        )
            ->setMaxResults($effectiveLimit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Finds repositories by list criteria.
     *
     * @return list<Repository>
     */
    public function findRepositories(
        ?string $search = null,
        string $sortBy = 'stars',
        string $sortDirection = 'DESC',
        int $limit = 100,
        int $offset = 0,
        ?ResolvedStarRangeScope $scope = null,
        ?int $maxRepositories = null,
        ?array $repositoryIds = null,
    ): array {
        $effectiveLimit = $this->resolveEffectiveLimit($limit, $offset, $scope, $maxRepositories);
        if ($effectiveLimit === 0) {
            return [];
        }

        return $this->createListQueryBuilder(
            $search,
            $sortBy,
            $sortDirection,
            $scope,
            $repositoryIds ?? $this->resolveScopedTopRepositoryIds($scope, $maxRepositories),
        )
            ->setMaxResults($effectiveLimit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Counts repositories matching the given criteria.
     */
    public function countRepositories(
        ?string $search = null,
        ?ResolvedStarRangeScope $scope = null,
        ?int $maxRepositories = null,
        ?array $repositoryIds = null,
    ): int {
        $repositoryIds ??= $this->resolveScopedTopRepositoryIds($scope, $maxRepositories);
        $normalizedSearch = $this->normalizedSearch($search);

        if ($repositoryIds !== null && $normalizedSearch === null) {
            return count($repositoryIds);
        }

        if ($scope !== null && $maxRepositories !== null) {
            $cacheKey = sprintf(
                'repository_query_builder.scoped_count.%s.%d.%s.%s',
                $scope->key,
                $maxRepositories,
                $this->repositoryCacheVersionManager->currentVersion(),
                md5($normalizedSearch ?? '__all__'),
            );

            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($search, $scope, $repositoryIds): int {
                $item->expiresAfter(60);

                return $this->executeCountQuery($search, $scope, $repositoryIds);
            });
        }

        return $this->executeCountQuery($search, $scope, $repositoryIds);
    }

    /**
     * @return list<string>|null
     */
    public function resolveScopedRepositoryIds(?ResolvedStarRangeScope $scope = null, ?int $maxRepositories = null, ?array &$debugTimings = null): ?array
    {
        return $this->resolveScopedTopRepositoryIds($scope, $maxRepositories, $debugTimings);
    }

    /**
     * @param list<string>|null $repositoryIds
     */
    private function createBaseQueryBuilder(
        ?string $search = null,
        ?ResolvedStarRangeScope $scope = null,
        ?array $repositoryIds = null,
    ): QueryBuilder {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Repository::class, 'repository');

        $this->applyScope($qb, $scope);
        $this->applyRepositoryIds($qb, $repositoryIds);

        $normalizedSearch = $this->normalizedSearch($search);
        if ($normalizedSearch === null) {
            return $qb;
        }

        return $qb
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(repository.name)', ':search'),
                    $qb->expr()->like('LOWER(repository.description)', ':search')
                )
            )
            ->setParameter('search', '%' . $normalizedSearch . '%');
    }

    private function applyScope(QueryBuilder $qb, ?ResolvedStarRangeScope $scope): void
    {
        if ($scope === null) {
            return;
        }

        $qb->andWhere('repository.stars >= :scope_min_stars')
            ->setParameter('scope_min_stars', $scope->min);

        if ($scope->max !== null) {
            $qb->andWhere('repository.stars <= :scope_max_stars')
                ->setParameter('scope_max_stars', $scope->max);
        }
    }

    /**
     * @param list<string>|null $repositoryIds
     */
    private function applyRepositoryIds(QueryBuilder $qb, ?array $repositoryIds): void
    {
        if ($repositoryIds === null) {
            return;
        }

        if ($repositoryIds === []) {
            $qb->andWhere('1 = 0');

            return;
        }

        $qb->andWhere('repository.id IN (:scoped_repository_ids)')
            ->setParameter('scoped_repository_ids', $repositoryIds);
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortDirection): QueryBuilder
    {
        if (!in_array($sortBy, self::VALID_SORT_FIELDS, true)) {
            $sortBy = 'stars';
        }

        $validSortDirection = mb_strtoupper($sortDirection) === 'ASC' ? 'ASC' : 'DESC';

        $qb->orderBy("repository.{$sortBy}", $validSortDirection);

        if ($sortBy !== 'id') {
            $qb->addOrderBy('repository.id', 'ASC');
        }

        return $qb;
    }

    /**
     * @return list<string>|null
     */
    private function resolveScopedTopRepositoryIds(?ResolvedStarRangeScope $scope, ?int $maxRepositories, ?array &$debugTimings = null): ?array
    {
        if ($scope === null || $maxRepositories === null) {
            if ($this->stargazerTimingEnabled) {
                $debugTimings = [
                    'skipped' => true,
                    'reason' => 'missing_scope_or_limit',
                ];
            }

            return null;
        }

        if ($maxRepositories <= 0) {
            if ($this->stargazerTimingEnabled) {
                $debugTimings = [
                    'skipped' => true,
                    'reason' => 'non_positive_limit',
                ];
            }

            return [];
        }

        $startedAt = microtime(true);
        $datasetVersionStartedAt = microtime(true);
        $datasetVersion = $this->repositoryCacheVersionManager->currentVersion();
        $datasetVersionMs = (int) round((microtime(true) - $datasetVersionStartedAt) * 1000);
        $cacheKey = sprintf(
            'repository_query_builder.scoped_ids.%s.%d.%s',
            $scope->key,
            $maxRepositories,
            $datasetVersion,
        );
        $cacheMiss = false;
        $timings = [
            'dataset_version_ms' => $datasetVersionMs,
            'cache_lookup_ms' => 0,
            'build_query_ms' => 0,
            'query_execute_ms' => 0,
            'hydration_ms' => 0,
            'total_ms' => 0,
            'resolved_count' => 0,
            'cache_hit' => false,
            'cache_key' => $cacheKey,
            'scope_key' => $scope->key,
            'max_repositories' => $maxRepositories,
            'dataset_version' => $datasetVersion,
            'sql' => null,
        ];
        $cacheLookupStartedAt = microtime(true);

        /** @var list<string> $repositoryIds */
        $repositoryIds = $this->cache->get($cacheKey, function (ItemInterface $item) use ($scope, $maxRepositories, &$cacheMiss, &$timings): array {
            $cacheMiss = true;
            $item->expiresAfter(60);
            $queryBuildStartedAt = microtime(true);

            $qb = $this->entityManager->createQueryBuilder()
                ->select('repository.id')
                ->from(Repository::class, 'repository');

            $this->applyScope($qb, $scope);
            $this->applySorting($qb, 'stars', 'DESC');
            $query = $qb->setMaxResults($maxRepositories)->getQuery();
            $timings['build_query_ms'] = (int) round((microtime(true) - $queryBuildStartedAt) * 1000);
            $timings['sql'] = $query->getSQL();
            $queryExecuteStartedAt = microtime(true);
            $singleColumnResult = $query->getSingleColumnResult();
            $timings['query_execute_ms'] = (int) round((microtime(true) - $queryExecuteStartedAt) * 1000);
            $hydrationStartedAt = microtime(true);
            $repositoryIds = array_map(
                static fn (mixed $repositoryId): string => (string) $repositoryId,
                $singleColumnResult,
            );
            $timings['hydration_ms'] = (int) round((microtime(true) - $hydrationStartedAt) * 1000);

            return $repositoryIds;
        });
        $timings['cache_lookup_ms'] = (int) round((microtime(true) - $cacheLookupStartedAt) * 1000);
        $timings['cache_hit'] = !$cacheMiss;
        $timings['resolved_count'] = count($repositoryIds);
        $timings['total_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        if ($this->stargazerTimingEnabled) {
            $debugTimings = $timings;
            $this->logger->info('Scoped repository ID resolution timing.', $timings);
        }

        return $repositoryIds;
    }

    private function executeCountQuery(
        ?string $search,
        ?ResolvedStarRangeScope $scope,
        ?array $repositoryIds,
    ): int {
        return (int) $this->createBaseQueryBuilder(
            $search,
            $scope,
            $repositoryIds,
        )
            ->select('COUNT(repository.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function normalizedSearch(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }

        return $this->searchQueryNormalizer->normalize($search);
    }
    private function resolveEffectiveLimit(
        int $limit,
        int $offset,
        ?ResolvedStarRangeScope $scope,
        ?int $maxRepositories,
    ): int {
        if ($scope === null || $maxRepositories === null) {
            return $limit;
        }

        if ($offset >= $maxRepositories) {
            return 0;
        }

        return min($limit, $maxRepositories - $offset);
    }
}
