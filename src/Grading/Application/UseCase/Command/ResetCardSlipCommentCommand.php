<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The adviser puts a learner's card slip comments back to the suggestions. */
final readonly class ResetCardSlipCommentCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $periodId,
        public int $enrollmentId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'periodId' => $this->periodId,
            'enrollmentId' => $this->enrollmentId,
        ];
    }
}
