<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The current school year's period the principal is about to lock, when they may lock the school's grades. */
final readonly class GetGradeLockCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $periodId,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'schoolId' => $this->schoolId, 'periodId' => $this->periodId];
    }
}
