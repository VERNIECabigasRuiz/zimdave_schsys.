<?php

declare(strict_types=1);

namespace App\Nutrition\Domain\Service\Sf8;

use App\Learners\Domain\Model\Enrollment;

/** One learner's line on SF8. A row that is not weighed has a result without BMI or categories. */
final readonly class Sf8Row
{
    public function __construct(
        public Enrollment $enrollment,
        public bool $male,
        public string $name,
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
