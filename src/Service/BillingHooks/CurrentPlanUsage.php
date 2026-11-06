<?php

namespace App\Service\BillingHooks;

use App\Billing\Domain\Service\PlanUsageInterface;
use App\Repository\SchoolRepository;

class CurrentPlanUsage implements PlanUsageInterface
{
    public function __construct(private readonly SchoolRepository $schools)
    {
    }

    public function schoolCountsByPlan(): array
    {
        return $this->schools->countByPlan();
    }
}
