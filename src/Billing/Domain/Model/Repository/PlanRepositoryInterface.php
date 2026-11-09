<?php

declare(strict_types=1);

namespace App\Billing\Domain\Model\Repository;

use App\Billing\Domain\Model\Plan;

interface PlanRepositoryInterface
{
    public function findById(int $id): ?Plan;

    public function findByName(string $name): ?Plan;

    /** @return list<Plan> by name */
    public function findAllOrdered(): array;

    /** Adds the plan; it is saved on the next flush. */
    public function add(Plan $plan): void;

    /** Removes the plan on the next flush. */
    public function remove(Plan $plan): void;
}
