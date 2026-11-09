<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** Every subject's grades of a section of the school for a period. */
final readonly class GetSectionGradesCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $sectionId,
        public ?int $periodId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'sectionId' => $this->sectionId,
            'periodId' => $this->periodId,
        ];
    }
}
