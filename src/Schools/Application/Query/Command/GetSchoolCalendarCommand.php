<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** A school's days off in the current school year. */
final readonly class GetSchoolCalendarCommand implements Query
{
    public function __construct(public int $schoolId)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId];
    }
}
