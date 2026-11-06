<?php

declare(strict_types=1);

namespace App\Core\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The newest entries of the "Recent activity" feed. */
final readonly class GetRecentActivityCommand implements Query
{
    public function __construct(public int $limit)
    {
    }

    public function toLogArray(): array
    {
        return ['limit' => $this->limit];
    }
}
