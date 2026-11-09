<?php

declare(strict_types=1);

namespace App\Dashboards\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The principal's "Classes right now" card. */
final readonly class GetPrincipalNowCommand implements Query
{
    public function __construct(public int $actorId)
    {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId];
    }
}
