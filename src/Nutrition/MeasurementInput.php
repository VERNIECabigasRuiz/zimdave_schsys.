<?php

namespace App\Nutrition;

/** What the adviser typed for one learner: kilograms, metres and a remark. All empty means no measurement. */
final readonly class MeasurementInput
{
    public function __construct(
        public int $enrollmentId,
        public ?float $weightKg,
        public ?float $heightM,
        public ?string $remarks,
    ) {
    }
}
