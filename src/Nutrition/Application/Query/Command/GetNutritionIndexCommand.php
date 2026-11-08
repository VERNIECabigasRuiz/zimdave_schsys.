<?php

declare(strict_types=1);

namespace App\Nutrition\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The adviser's SF8 page: their section and both weighing periods. */
final readonly class GetNutritionIndexCommand implements Query
{
    public function __construct(public int $actorId)
    {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId];
    }
}
