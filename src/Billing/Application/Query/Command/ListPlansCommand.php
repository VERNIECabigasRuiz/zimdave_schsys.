<?php

declare(strict_types=1);

namespace App\Billing\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** Every plan with the number of schools on it. */
final readonly class ListPlansCommand implements Query
{
    public function toLogArray(): array
    {
        return [];
    }
}
