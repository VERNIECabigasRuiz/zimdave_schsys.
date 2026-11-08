<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The class's teacher saves its grades for a period; the ids come from the sheet page. */
final readonly class SaveGradeSheetCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $loadId,
        public int $periodId,
        public int $schoolId,
        public int $sectionId,
        public int $subjectId,
        /** @var array<int, ?int> by enrollment id */
        public array $values,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'loadId' => $this->loadId,
            'periodId' => $this->periodId,
            'schoolId' => $this->schoolId,
            'sectionId' => $this->sectionId,
            'subjectId' => $this->subjectId,
            'values' => \count($this->values),
        ];
    }
}
