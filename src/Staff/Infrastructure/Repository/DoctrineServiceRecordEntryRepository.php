<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure\Repository;

use App\Access\Domain\Model\User;
use App\Staff\Domain\Model\Repository\ServiceRecordEntryRepositoryInterface;
use App\Staff\Domain\Model\ServiceRecordEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceRecordEntry>
 */
class DoctrineServiceRecordEntryRepository extends ServiceEntityRepository implements
    ServiceRecordEntryRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceRecordEntry::class);
    }

    public function findById(int $id): ?ServiceRecordEntry
    {
        return $this->find($id);
    }

    public function findForEmployee(User $employee): array
    {
        return $this->findBy(['employee' => $employee], ['fromDate' => 'ASC', 'id' => 'ASC']);
    }

    public function findCurrent(User $employee): ?ServiceRecordEntry
    {
        return $this->findOneBy(['employee' => $employee, 'toDate' => null]);
    }

    public function add(ServiceRecordEntry $entry): void
    {
        $this->getEntityManager()->persist($entry);
    }

    public function remove(ServiceRecordEntry $entry): void
    {
        $this->getEntityManager()->remove($entry);
    }
}
