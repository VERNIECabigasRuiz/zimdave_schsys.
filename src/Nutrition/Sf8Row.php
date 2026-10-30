<?php

namespace App\Nutrition;

use App\Attendance\RosterEntry;

/** One learner's line on SF8. A row that is not weighed has a result without BMI or categories. */
final readonly class Sf8Row
{
    public function __construct(
        public RosterEntry $entry,
        public ?string $lrn,
        public ?\DateTimeImmutable $birthDate,
        public NutritionResult $result,
        public ?float $weightKg,
        public ?float $heightM,
        public ?string $remarks,
        public bool $weighed,
    ) {
    }
}
