<?php

namespace App\Repository;

use App\Entity\ServiceRecord;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceRecord>
 */
class ServiceRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceRecord::class);
    }

    /** The employee's record, or a new one that isn't persisted yet. */
    public function findOrNew(User $employee): ServiceRecord
    {
        return $this->findOneBy(['employee' => $employee]) ?? new ServiceRecord($employee);
    }
}
