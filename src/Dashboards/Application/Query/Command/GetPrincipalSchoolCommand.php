<?php

declare(strict_types=1);

namespace App\Dashboards\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The school the principal heads this school year, for the menu shortcuts. */
final readonly class GetPrincipalSchoolCommand implements Query
{
    public function __construct(public int $actorId)
    {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId];
    }
}
