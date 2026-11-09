<?php

declare(strict_types=1);

namespace App\Attendance\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The principal or an administrator reopens a section's submitted month so its adviser can change it. */
final readonly class ReopenSf2Command implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $sectionId,
        public \DateTimeImmutable $month,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'sectionId' => $this->sectionId,
            'month' => $this->month->format('Y-m'),
        ];
    }
}
