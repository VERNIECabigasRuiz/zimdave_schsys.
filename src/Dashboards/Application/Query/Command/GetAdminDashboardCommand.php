<?php

declare(strict_types=1);

namespace App\Dashboards\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The administrator's dashboard: plans ending soon, the year's figures and the account counts. */
final readonly class GetAdminDashboardCommand implements Query
{
    public function __construct(public \DateTimeImmutable $expiringBy)
    {
    }

    public function toLogArray(): array
    {
        return ['expiringBy' => $this->expiringBy->format('Y-m-d')];
    }
}
