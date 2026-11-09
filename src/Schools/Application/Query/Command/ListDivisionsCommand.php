<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** Every division, by region then name. */
final readonly class ListDivisionsCommand implements Query
{
    public function toLogArray(): array
    {
        return [];
    }
}
