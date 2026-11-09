<?php

declare(strict_types=1);

namespace App\Schools\Infrastructure\Billing;

use App\Billing\Domain\Service\PlanUsageInterface;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;

class CurrentPlanUsage implements PlanUsageInterface
{
    public function __construct(private readonly SchoolRepositoryInterface $schools)
    {
    }

    public function schoolCountsByPlan(): array
    {
        return $this->schools->countByPlan();
    }
}
