<?php

namespace App\Repository;

use App\Entity\StaffIdDetails;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaffIdDetails>
 */
class StaffIdDetailsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffIdDetails::class);
    }

    /** The person's saved ID details, or new empty ones (not persisted). */
    public function findOrNew(User $user): StaffIdDetails
    {
        return $this->findOneBy(['user' => $user]) ?? new StaffIdDetails($user);
    }
}
