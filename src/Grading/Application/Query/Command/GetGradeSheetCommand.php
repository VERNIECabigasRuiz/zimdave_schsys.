<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** A class's grade sheet for a period, as its teacher, adviser or principal sees it. */
final readonly class GetGradeSheetCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $loadId,
        public int $periodId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'loadId' => $this->loadId,
            'periodId' => $this->periodId,
        ];
    }
}
