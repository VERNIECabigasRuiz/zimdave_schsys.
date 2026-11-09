<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** What a new lesson plan may be for: the teacher's classes this school year. */
final readonly class GetLessonPlanStartCommand implements Query
{
    public function __construct(
        public int $actorId,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId];
    }
}
