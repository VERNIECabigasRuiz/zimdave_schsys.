<?php

declare(strict_types=1);

namespace App\Dashboards\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The principal's dashboard for their school this school year. */
final readonly class GetPrincipalDashboardCommand implements Query
{
    public function __construct(public int $actorId)
    {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId];
    }
}
