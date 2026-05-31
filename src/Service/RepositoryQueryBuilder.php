<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Repository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Service responsible for building queries for repository listings with filtering and sorting.
 */
final class RepositoryQueryBuilder
{
    private const VALID_SORT_FIELDS = ['stars', 'created_at', 'pushed_at', 'name'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SearchQueryNormalizer $searchQueryNormalizer,
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
            $this->resolveScopedTopRepositoryIds($scope, $maxRepositories),
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
            $this->resolveScopedTopRepositoryIds($scope, $maxRepositories),
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
    ): int {
        return (int) $this->createBaseQueryBuilder(
            $search,
            $scope,
            $this->resolveScopedTopRepositoryIds($scope, $maxRepositories),
        )
            ->select('COUNT(repository.id)')
            ->getQuery()
            ->getSingleScalarResult();
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

        if ($search === null) {
            return $qb;
        }

        $normalizedSearch = $this->searchQueryNormalizer->normalize($search);
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
    private function resolveScopedTopRepositoryIds(?ResolvedStarRangeScope $scope, ?int $maxRepositories): ?array
    {
        if ($scope === null || $maxRepositories === null) {
            return null;
        }

        if ($maxRepositories <= 0) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('repository.id')
            ->from(Repository::class, 'repository');

        $this->applyScope($qb, $scope);
        $this->applySorting($qb, 'stars', 'DESC');

        /** @var list<string> $repositoryIds */
        $repositoryIds = array_map(
            static fn (mixed $repositoryId): string => (string) $repositoryId,
            $qb
                ->setMaxResults($maxRepositories)
                ->getQuery()
                ->getSingleColumnResult(),
        );

        return $repositoryIds;
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
