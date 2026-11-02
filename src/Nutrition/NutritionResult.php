<?php

namespace App\Nutrition;

/** One learner's computed nutritional status on the date of weighing. Null categories mean "not computed". */
final readonly class NutritionResult
{
    public function __construct(
        public ?int $ageMonths,
        public ?float $bmi,
        public ?BmiCategory $bmiCategory,
        public ?HfaCategory $hfaCategory,
    ) {
    }

    public function ageYears(): ?int
    {
        return null === $this->ageMonths ? null : intdiv($this->ageMonths, 12);
    }
}
