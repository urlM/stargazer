<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SyncLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SyncLog>
 */
final class SyncLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncLog::class);
    }

    public function save(SyncLog $syncLog, bool $flush = false): void
    {
        $this->getEntityManager()->persist($syncLog);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByCorrelationId(string $correlationId): ?SyncLog
    {
        return $this->findOneBy(['correlationId' => $correlationId]);
    }
}
