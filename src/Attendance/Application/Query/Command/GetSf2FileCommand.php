<?php

declare(strict_types=1);

namespace App\Attendance\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** A section's SF2 for the month as an .xlsx file: the adviser's own section ($schoolId null) or $sectionId. */
final readonly class GetSf2FileCommand implements Query
{
    public function __construct(
        public int $actorId,
        public ?int $schoolId,
        public ?int $sectionId,
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
