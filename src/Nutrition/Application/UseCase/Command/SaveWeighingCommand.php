<?php

declare(strict_types=1);

namespace App\Nutrition\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Nutrition\Domain\Model\NutritionPeriod;

/** The adviser saves the date of weighing and the measurements of their section ($sectionId, from the page). */
final readonly class SaveWeighingCommand implements UseCase
{
    /** @param list<MeasurementInput> $inputs */
    public function __construct(
        public int $actorId,
        public int $sectionId,
        public NutritionPeriod $period,
        public \DateTimeImmutable $weighedOn,
        public array $inputs,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'sectionId' => $this->sectionId,
            'period' => $this->period->value,
            'weighedOn' => $this->weighedOn->format('Y-m-d'),
            'inputs' => \count($this->inputs),
        ];
    }
}
