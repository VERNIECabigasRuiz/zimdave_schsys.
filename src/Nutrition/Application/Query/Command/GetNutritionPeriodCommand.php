<?php

declare(strict_types=1);

namespace App\Nutrition\Application\Query\Command;

use App\Core\Application\Bus\Query;
use App\Nutrition\Domain\Model\NutritionPeriod;

/** A section's weighing sheet for a period: the adviser's own ($schoolId null), or $sectionId of the school. */
final readonly class GetNutritionPeriodCommand implements Query
{
    public function __construct(
        public int $actorId,
        public ?int $schoolId,
        public ?int $sectionId,
        public NutritionPeriod $period,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'sectionId' => $this->sectionId,
            'period' => $this->period->value,
        ];
    }
}
