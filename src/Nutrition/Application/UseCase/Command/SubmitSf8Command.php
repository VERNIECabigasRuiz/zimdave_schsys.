<?php

declare(strict_types=1);

namespace App\Nutrition\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Nutrition\Domain\Model\NutritionPeriod;

/**
 * The adviser hands the period's SF8 of their section ($sectionId, from the page) to the principal;
 * $confirmUnweighed says learners without weight and height stay unweighed.
 */
final readonly class SubmitSf8Command implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $sectionId,
        public NutritionPeriod $period,
        public bool $confirmUnweighed,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'sectionId' => $this->sectionId,
            'period' => $this->period->value,
            'confirmUnweighed' => $this->confirmUnweighed,
        ];
    }
}
