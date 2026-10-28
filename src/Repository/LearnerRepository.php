<?php

namespace App\Repository;

use App\Entity\Learner;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Learner>
 */
class LearnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Learner::class);
    }

    public function findOneByLrn(string $lrn): ?Learner
    {
        return $this->findOneBy(['lrn' => $lrn]);
    }

    public function findOneByUser(User $user): ?Learner
    {
        return $this->findOneBy(['user' => $user]);
    }
}
