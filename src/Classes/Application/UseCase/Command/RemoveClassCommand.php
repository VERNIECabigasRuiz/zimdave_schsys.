<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** Takes one meeting off a section's timetable. */
final readonly class RemoveClassCommand implements UseCase
{
    public function __construct(public int $schoolId, public int $sectionId, public int $entryId)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId, 'sectionId' => $this->sectionId, 'entryId' => $this->entryId];
    }
}
