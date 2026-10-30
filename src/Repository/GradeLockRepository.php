<?php

namespace App\Repository;

use App\Entity\GradeLock;
use App\Entity\GradingPeriod;
use App\Entity\School;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GradeLock>
 */
class GradeLockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GradeLock::class);
    }

    public function findFor(School $school, GradingPeriod $period): ?GradeLock
    {
        return $this->findOneBy(['school' => $school, 'gradingPeriod' => $period]);
    }
}
