<?php

declare(strict_types=1);

namespace App\Billing\Application\Api;

use App\Billing\Domain\Model\Plan;
use App\Billing\Domain\Model\Repository\PlanRepositoryInterface;

final class PlanCatalog implements PlanCatalogInterface
{
    public function __construct(private readonly PlanRepositoryInterface $plans)
    {
    }

    public function findById(int $id): ?Plan
    {
        return $this->plans->findById($id);
    }

    public function findByName(string $name): ?Plan
    {
        return $this->plans->findByName($name);
    }

    public function findAllOrdered(): array
    {
        return $this->plans->findAllOrdered();
    }
}
