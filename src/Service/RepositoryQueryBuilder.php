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
     *
     * @return QueryBuilder The configured query builder.
     */
    public function createListQueryBuilder(
        ?string $search = null,
        string $sortBy = 'stars',
        string $sortDirection = 'DESC',
    ): QueryBuilder {
        $qb = $this->createBaseQueryBuilder($search);

        return $this->applySorting($qb, $sortBy, $sortDirection)
            ->select('repository');
    }

    /**
     * Creates a lightweight query builder for repository list rows.
     *
     * @return QueryBuilder The configured query builder returning scalar list fields.
     */
    public function createListItemsQueryBuilder(
        ?string $search = null,
        string $sortBy = 'stars',
        string $sortDirection = 'DESC',
    ): QueryBuilder {
        $qb = $this->createBaseQueryBuilder($search);

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
    ): array {
        return $this->createListItemsQueryBuilder($search, $sortBy, $sortDirection)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getArrayResult();
    }

    private function createBaseQueryBuilder(?string $search = null): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Repository::class, 'repository');

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

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortDirection): QueryBuilder
    {
        if (!in_array($sortBy, self::VALID_SORT_FIELDS, true)) {
            $sortBy = 'stars';
        }

        $validSortDirection = mb_strtoupper($sortDirection) === 'ASC' ? 'ASC' : 'DESC';

        $qb->orderBy("repository.{$sortBy}", $validSortDirection);

        // Add secondary sort by ID for consistent ordering
        if ($sortBy !== 'id') {
            $qb->addOrderBy('repository.id', 'ASC');
        }

        return $qb;
    }

    /**
     * Finds repositories by list criteria.
     *
     * @param string|null $search        Optional search query.
     * @param string      $sortBy        Field to sort by.
     * @param string      $sortDirection Sort direction.
     * @param int         $limit         Maximum results.
     * @param int         $offset        Pagination offset.
     *
     * @return list<Repository>
     */
    public function findRepositories(
        ?string $search = null,
        string $sortBy = 'stars',
        string $sortDirection = 'DESC',
        int $limit = 100,
        int $offset = 0,
    ): array {
        return $this->createListQueryBuilder($search, $sortBy, $sortDirection)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Counts repositories matching the given criteria.
     *
     * @param string|null $search Optional search query.
     *
     * @return int The count of matching repositories.
     */
    public function countRepositories(?string $search = null): int
    {
        $qb = $this->createBaseQueryBuilder($search);

        return (int) $qb
            ->select('COUNT(repository.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
