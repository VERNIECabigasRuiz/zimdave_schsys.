<?php

declare(strict_types=1);

namespace App\Billing\Application\Api;

use App\Billing\Domain\Model\Plan;

/**
 * The plans other modules may read.
 */
interface PlanCatalogInterface
{
    public function findById(int $id): ?Plan;

    public function findByName(string $name): ?Plan;

    /** @return list<Plan> by name */
    public function findAllOrdered(): array;
}
