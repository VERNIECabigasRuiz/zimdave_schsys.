<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The teacher's change request form for a class and a period, or why they can't send one now. */
final readonly class GetGradeChangeFormCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $loadId,
        public int $periodId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'loadId' => $this->loadId,
            'periodId' => $this->periodId,
        ];
    }
}
