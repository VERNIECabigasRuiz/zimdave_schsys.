<?php

namespace App\Repository;

use App\Entity\ServiceRecordEntry;
use App\Access\Domain\Model\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceRecordEntry>
 */
class ServiceRecordEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceRecordEntry::class);
    }

    /** @return list<ServiceRecordEntry> the employee's rows, oldest first */
    public function findForEmployee(User $employee): array
    {
        return $this->findBy(['employee' => $employee], ['fromDate' => 'ASC', 'id' => 'ASC']);
    }

    /** The row that runs to the present, which holds the employee's current designation. */
    public function findCurrent(User $employee): ?ServiceRecordEntry
    {
        return $this->findOneBy(['employee' => $employee, 'toDate' => null]);
    }
}
