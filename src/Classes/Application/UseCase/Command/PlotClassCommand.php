<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** Puts one meeting of a subject on a weekday (1 = Monday) and class period. */
final readonly class PlotClassCommand implements UseCase
{
    public function __construct(
        public int $schoolId,
        public int $sectionId,
        public int $subjectId,
        public int $day,
        public int $periodId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'schoolId' => $this->schoolId,
            'sectionId' => $this->sectionId,
            'subjectId' => $this->subjectId,
            'day' => $this->day,
            'periodId' => $this->periodId,
        ];
    }
}
