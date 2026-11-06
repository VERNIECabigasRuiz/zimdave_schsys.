<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Repository;

use App\Core\Domain\Model\ActivityLog;
use App\Core\Domain\Model\Repository\ActivityLogRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityLog>
 */
class DoctrineActivityLogRepository extends ServiceEntityRepository implements ActivityLogRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    public function record(ActivityLog $entry): void
    {
        $this->getEntityManager()->persist($entry);
    }

    public function findRecent(int $limit): array
    {
        /** @var list<ActivityLog> $entries */
        $entries = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $entries;
    }
}
