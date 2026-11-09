<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The school's sections this school year, grouped by grade level. */
final readonly class ListSectionsCommand implements Query
{
    public function __construct(public int $schoolId)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId];
    }
}
