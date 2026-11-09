<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The school's day of periods this school year, in time order. */
final readonly class ListClassPeriodsCommand implements Query
{
    public function __construct(public int $schoolId)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId];
    }
}
