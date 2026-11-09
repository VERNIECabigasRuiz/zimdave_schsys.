<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The id of the teacher's plan for one of their classes and the week of $week. */
final readonly class GetLessonPlanIdCommand implements Query
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
