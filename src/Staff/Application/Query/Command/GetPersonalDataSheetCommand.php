<?php

declare(strict_types=1);

namespace App\Staff\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The person's own Personal Data Sheet, for a profile page. */
final readonly class GetPersonalDataSheetCommand implements Query
{
    public function __construct(public int $actorId)
    {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId];
    }
}
