<?php

declare(strict_types=1);

namespace App\Nutrition\Domain\Service\Classification;

use App\Nutrition\Domain\Service\Sf8\NutritionResult;

/** Classifies a learner's BMI-for-age and height-for-age on the date of weighing. */
interface NutritionClassifierInterface
{
    /** Without a birth date, weight or height, the result has only what can be computed. */
    public function classify(
        bool $male,
        ?\DateTimeImmutable $birthDate,
        \DateTimeImmutable $weighedOn,
        ?float $weightKg,
        ?float $heightM,
    ): NutritionResult;
}
