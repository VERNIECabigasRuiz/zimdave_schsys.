<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The school's learners who failed a period's general average. */
final readonly class GetFailingLearnersCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public ?int $periodId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'periodId' => $this->periodId,
        ];
    }
}
