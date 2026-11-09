<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** Every lesson plan of the principal's school this school year. */
final readonly class GetSchoolLessonPlansCommand implements Query
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
