<?php

declare(strict_types=1);

namespace App\Attendance\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The adviser's roll call page for $day (today when null): who counts, the marks so far, and whether it is open. */
final readonly class GetRollCallPageCommand implements Query
{
    public function __construct(
        public int $actorId,
        public ?\DateTimeImmutable $day,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'day' => $this->day?->format('Y-m-d')];
    }
}
