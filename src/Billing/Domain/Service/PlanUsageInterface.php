<?php

declare(strict_types=1);

namespace App\Billing\Domain\Service;

/** How many schools use each plan; implemented by the schools module. */
interface PlanUsageInterface
{
    /** @return array<int, int> school count by plan id (plans without schools are left out) */
    public function schoolCountsByPlan(): array;
}
