<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure\Repository;

use App\Access\Domain\Model\User;
use App\Staff\Domain\Model\Repository\StaffIdDetailsRepositoryInterface;
use App\Staff\Domain\Model\StaffIdDetails;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaffIdDetails>
 */
class DoctrineStaffIdDetailsRepository extends ServiceEntityRepository implements StaffIdDetailsRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffIdDetails::class);
    }

    public function findFor(User $user): ?StaffIdDetails
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function add(StaffIdDetails $details): void
    {
        $this->getEntityManager()->persist($details);
    }
}
