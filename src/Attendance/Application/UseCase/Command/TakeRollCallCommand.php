<?php

declare(strict_types=1);

namespace App\Attendance\Application\UseCase\Command;

use App\Attendance\Domain\Model\LearnerMark;
use App\Core\Application\Bus\UseCase;

/** The adviser records the day's roll call of their section ($sectionId, from the roll call page). */
final readonly class TakeRollCallCommand implements UseCase
{
    /** @param array<int, ?LearnerMark> $marks keyed by enrollment id; no mark is present */
    public function __construct(
        public int $actorId,
        public int $sectionId,
        public \DateTimeImmutable $day,
        public array $marks,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'sectionId' => $this->sectionId,
            'day' => $this->day->format('Y-m-d'),
            'marks' => array_map(static fn (?LearnerMark $mark) => $mark?->value, $this->marks),
        ];
    }
}
