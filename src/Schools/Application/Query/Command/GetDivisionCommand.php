<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** One division. */
final readonly class GetDivisionCommand implements Query
{
    public function __construct(public int $id)
    {
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id];
    }
}
