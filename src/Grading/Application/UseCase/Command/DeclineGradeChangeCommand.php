<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The school's principal declines a change request with a note for the teacher. */
final readonly class DeclineGradeChangeCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $requestId,
        public string $note,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'requestId' => $this->requestId,
        ];
    }
}
