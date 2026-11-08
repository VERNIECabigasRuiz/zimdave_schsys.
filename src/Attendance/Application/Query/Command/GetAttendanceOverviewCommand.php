<?php

declare(strict_types=1);

namespace App\Attendance\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The school's attendance this school year: today, and every section's month ($month, or the current one). */
final readonly class GetAttendanceOverviewCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public ?\DateTimeImmutable $month,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'schoolId' => $this->schoolId, 'month' => $this->month?->format('Y-m')];
    }
}
