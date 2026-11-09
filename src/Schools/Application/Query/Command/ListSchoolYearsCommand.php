<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** Every school year, newest first, with how many schools have each period open. */
final readonly class ListSchoolYearsCommand implements Query
{
    public function toLogArray(): array
    {
        return [];
    }
}
