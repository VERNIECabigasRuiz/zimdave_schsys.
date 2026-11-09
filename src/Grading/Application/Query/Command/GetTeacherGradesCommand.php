<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The classes a teacher grades this school year, with each period's progress. */
final readonly class GetTeacherGradesCommand implements Query
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
