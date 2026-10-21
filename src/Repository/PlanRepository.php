<?php

namespace App\Repository;

use App\Entity\Plan;
use App\Entity\School;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Plan>
 */
class PlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Plan::class);
    }

    public function findByName(string $name): ?Plan
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * @return list<Plan>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * @return array<int, int> number of schools keyed by plan id
     */
    public function countSchoolsByPlan(): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(s.plan) AS planId', 'COUNT(s.id) AS schools')
            ->from(School::class, 's')
            ->groupBy('s.plan')
            ->getQuery()
            ->getArrayResult();

        return array_map('intval', array_column($rows, 'schools', 'planId'));
    }
}
