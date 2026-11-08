<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The principal unlocks a period's grades. */
final readonly class UnlockGradingPeriodCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $periodId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'periodId' => $this->periodId,
        ];
    }
}
