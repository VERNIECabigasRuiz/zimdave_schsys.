<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** A section's timetable for a term; an unknown or missing term means the section's first. */
final readonly class GetSectionTimetableCommand implements Query
{
    public function __construct(public int $schoolId, public int $sectionId, public ?int $term = null)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId, 'sectionId' => $this->sectionId, 'term' => $this->term];
    }
}
