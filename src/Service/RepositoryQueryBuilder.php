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
        $qb = $this->entityManager->createQueryBuilder()
            ->select('repository')
            ->from(Repository::class, 'repository');

        // Apply search filter if provided
        if ($search !== null) {
            $normalizedSearch = $this->searchQueryNormalizer->normalize($search);
            if ($normalizedSearch !== null) {
                $qb->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->like('LOWER(repository.name)', ':search'),
                        $qb->expr()->like('LOWER(repository.description)', ':search')
                    )
                )
                    ->setParameter('search', '%' . $normalizedSearch . '%');
            }
        }

        // Apply sorting
        $validSortFields = ['stars', 'created_at', 'pushed_at', 'name'];
        if (!in_array($sortBy, $validSortFields, true)) {
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
        $qb = $this->createListQueryBuilder($search);

        return (int) $qb
            ->select('COUNT(repository.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
