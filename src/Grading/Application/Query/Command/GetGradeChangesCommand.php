<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The school's pending or decided change requests this school year. */
final readonly class GetGradeChangesCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public string $status,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'status' => $this->status,
        ];
    }
}
