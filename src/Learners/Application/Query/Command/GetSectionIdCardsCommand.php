<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The ID cards of a section's learners as a PDF; an unknown $layout prints sheets. */
final readonly class GetSectionIdCardsCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public ?int $fixedSectionId,
        public int $sectionId,
        public string $layout,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'fixedSectionId' => $this->fixedSectionId,
            'sectionId' => $this->sectionId,
            'layout' => $this->layout,
        ];
    }
}
