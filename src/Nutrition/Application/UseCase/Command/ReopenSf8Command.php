<?php

declare(strict_types=1);

namespace App\Nutrition\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Nutrition\Domain\Model\NutritionPeriod;

/** The principal or an administrator reopens a section's submitted SF8; the result is the section's label. */
final readonly class ReopenSf8Command implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $sectionId,
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
