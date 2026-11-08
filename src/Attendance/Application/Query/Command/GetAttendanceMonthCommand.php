<?php

declare(strict_types=1);

namespace App\Attendance\Application\Query\Command;

use App\Core\Application\Bus\Query;

/**
 * A section's month with its SF2 figures: the adviser's own section ($schoolId null) or $sectionId of the
 * school. $month is the 1st of the month, or the current month when null.
 */
final readonly class GetAttendanceMonthCommand implements Query
{
    public function __construct(
        public int $actorId,
        public ?int $schoolId,
        public ?int $sectionId,
        public ?\DateTimeImmutable $month,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'sectionId' => $this->sectionId,
            'month' => $this->month?->format('Y-m'),
        ];
    }
}
