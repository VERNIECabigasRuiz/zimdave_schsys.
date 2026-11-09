<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The principal locks an unlocked class again. */
final readonly class LockClassGradesCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $sectionId,
        public int $subjectId,
        public int $periodId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'sectionId' => $this->sectionId,
            'subjectId' => $this->subjectId,
            'periodId' => $this->periodId,
        ];
    }
}
