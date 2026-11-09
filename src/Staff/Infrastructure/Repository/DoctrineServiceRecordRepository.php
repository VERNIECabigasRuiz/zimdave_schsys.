<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure\Repository;

use App\Access\Domain\Model\User;
use App\Staff\Domain\Model\Repository\ServiceRecordRepositoryInterface;
use App\Staff\Domain\Model\ServiceRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceRecord>
 */
class DoctrineServiceRecordRepository extends ServiceEntityRepository implements ServiceRecordRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceRecord::class);
    }

    public function findFor(User $employee): ?ServiceRecord
    {
        return $this->findOneBy(['employee' => $employee]);
    }

    public function add(ServiceRecord $record): void
    {
        $this->getEntityManager()->persist($record);
    }
}
