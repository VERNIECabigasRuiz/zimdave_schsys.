<?php

declare(strict_types=1);

namespace App\Billing\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** One plan. */
final readonly class GetPlanCommand implements Query
{
    public function __construct(public int $id)
    {
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id];
    }
}
