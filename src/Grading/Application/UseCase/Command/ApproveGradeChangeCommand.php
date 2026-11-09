<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The school's principal approves a change request; the ids come from a GetGradeChangeCommand. */
final readonly class ApproveGradeChangeCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $requestId,
        public int $sectionId,
        public int $subjectId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'requestId' => $this->requestId,
            'sectionId' => $this->sectionId,
            'subjectId' => $this->subjectId,
        ];
    }
}
