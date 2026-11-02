<?php

namespace App\Form;

use App\Nutrition\MeasurementInput;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** One learner's line on the weighing sheet, as the adviser types it. */
class NutritionEntry
{
    public function __construct(
        public int $enrollmentId,
        #[Assert\Range(notInRangeMessage: 'Enter weight in kilograms, between 5 and 200.', min: 5, max: 200)]
        public ?float $weightKg = null,
        #[Assert\Range(notInRangeMessage: 'Enter height in metres, e.g. 1.32.', min: 0.5, max: 2.5)]
        public ?float $heightM = null,
        #[Assert\Length(max: 100)]
        public ?string $remarks = null,
    ) {
    }

    #[Assert\Callback]
    public function validateBoth(ExecutionContextInterface $context): void
    {
        if ((null === $this->weightKg) !== (null === $this->heightM)) {
            $context->buildViolation('Enter both weight and height, or neither.')->addViolation();
        }
    }

    public function toInput(): MeasurementInput
    {
        return new MeasurementInput($this->enrollmentId, $this->weightKg, $this->heightM, $this->remarks);
    }
}
