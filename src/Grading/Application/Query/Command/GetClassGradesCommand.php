<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** Every subject's grades of the section the teacher advises, for a period. */
final readonly class GetClassGradesCommand implements Query
{
    public function __construct(
        public int $actorId,
        public ?int $periodId,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'periodId' => $this->periodId];
    }
}
