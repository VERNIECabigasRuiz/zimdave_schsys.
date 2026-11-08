<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The adviser's card slips page for a term (the default one when none is asked for). */
final readonly class GetCardSlipsCommand implements Query
{
    public function __construct(
        public int $actorId,
        public ?int $periodId,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'periodId' => $this->periodId];
    }
}
