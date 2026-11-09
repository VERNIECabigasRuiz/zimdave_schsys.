<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The teacher opens the plan of one of their classes for the week of $week, starting it when there is none. */
final readonly class StartLessonPlanCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $loadId,
        public \DateTimeImmutable $week,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'loadId' => $this->loadId, 'week' => $this->week->format('Y-m-d')];
    }
}
