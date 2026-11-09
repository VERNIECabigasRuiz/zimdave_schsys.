<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The principal lets one class's teacher correct a locked period's grades. */
final readonly class UnlockClassGradesCommand implements UseCase
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
