<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The teacher's lesson plans this school year. */
final readonly class GetTeacherLessonPlansCommand implements Query
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
