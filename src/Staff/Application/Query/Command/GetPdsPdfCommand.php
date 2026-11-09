<?php

declare(strict_types=1);

namespace App\Staff\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The person's own Personal Data Sheet as a PDF. */
final readonly class GetPdsPdfCommand implements Query
{
    public function __construct(public int $actorId)
    {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId];
    }
}
