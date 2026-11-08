<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** Every class's grades progress at the school for a period (or the default one). */
final readonly class GetSchoolGradesCommand implements Query
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
