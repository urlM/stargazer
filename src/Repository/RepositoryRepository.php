<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Repository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Repository>
 */
final class RepositoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Repository::class);
    }

    public function save(Repository $repository, bool $flush = false): void
    {
        $this->getEntityManager()->persist($repository);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return list<Repository>
     */
    public function findTopRepositories(int $limit = 100): array
    {
        return $this->createQueryBuilder('repository')
            ->orderBy('repository.stars', 'DESC')
            ->addOrderBy('repository.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
