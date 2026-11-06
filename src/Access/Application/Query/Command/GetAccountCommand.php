<?php

declare(strict_types=1);

namespace App\Access\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** One account, with the learner it belongs to (if any). */
final readonly class GetAccountCommand implements Query
{
    public function __construct(public int $id)
    {
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id];
    }
}
