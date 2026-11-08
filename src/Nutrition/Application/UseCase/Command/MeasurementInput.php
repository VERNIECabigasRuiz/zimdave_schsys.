<?php

declare(strict_types=1);

namespace App\Nutrition\Application\UseCase\Command;

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
