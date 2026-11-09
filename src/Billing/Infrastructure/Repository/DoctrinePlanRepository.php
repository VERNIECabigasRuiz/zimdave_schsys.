<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Repository;

use App\Billing\Domain\Model\Plan;
use App\Billing\Domain\Model\Repository\PlanRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Plan>
 */
class DoctrinePlanRepository extends ServiceEntityRepository implements PlanRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Plan::class);
    }

    public function findById(int $id): ?Plan
    {
        return $this->find($id);
    }

    public function findByName(string $name): ?Plan
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    public function add(Plan $plan): void
    {
        $this->getEntityManager()->persist($plan);
    }

    public function remove(Plan $plan): void
    {
        $this->getEntityManager()->remove($plan);
    }
}
