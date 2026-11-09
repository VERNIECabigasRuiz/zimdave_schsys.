<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The principal or an administrator opens a grading period at the school. */
final readonly class OpenGradingPeriodCommand implements UseCase
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
