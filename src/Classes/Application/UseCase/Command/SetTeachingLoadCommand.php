<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** Picks the teacher of a subject in a section; a null teacher clears it. */
final readonly class SetTeachingLoadCommand implements UseCase
{
    public function __construct(
        public int $schoolId,
        public int $sectionId,
        public int $subjectId,
        public ?int $teacherId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'schoolId' => $this->schoolId,
            'sectionId' => $this->sectionId,
            'subjectId' => $this->subjectId,
            'teacherId' => $this->teacherId,
        ];
    }
}
