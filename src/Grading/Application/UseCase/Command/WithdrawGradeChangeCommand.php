<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The teacher who sent a change request takes it back; the school id comes from a GetGradeChangeCommand. */
final readonly class WithdrawGradeChangeCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $requestId,
        public int $schoolId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'requestId' => $this->requestId,
            'schoolId' => $this->schoolId,
        ];
    }
}
