<?php

declare(strict_types=1);

namespace App\Nutrition\Application\Query\Command;

use App\Core\Application\Bus\Query;
use App\Nutrition\Domain\Model\NutritionPeriod;

/** The school's SF8 overview this school year for a period, or both periods side by side ($compare). */
final readonly class GetNutritionOverviewCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public NutritionPeriod $period,
        public bool $compare,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'period' => $this->period->value,
            'compare' => $this->compare,
        ];
    }
}
