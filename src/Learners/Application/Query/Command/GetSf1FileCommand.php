<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** A section's SF1 (School Register) as an .xls file, as the school or its adviser downloads it. */
final readonly class GetSf1FileCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public ?int $fixedSectionId,
        public int $sectionId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'fixedSectionId' => $this->fixedSectionId,
            'sectionId' => $this->sectionId,
        ];
    }
}
