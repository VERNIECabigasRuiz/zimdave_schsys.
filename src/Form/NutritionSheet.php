<?php

namespace App\Form;

use App\Nutrition\MeasurementInput;
use App\Nutrition\Sf8Report;
use App\Nutrition\Sf8Row;
use Symfony\Component\Validator\Constraints as Assert;

/** The adviser's weighing sheet for a period: the date of weighing and a line per learner. */
class NutritionSheet
{
    /** @param list<NutritionEntry> $entries */
    public function __construct(
        #[Assert\NotNull(message: 'Enter the date of weighing.')]
        public ?\DateTimeImmutable $weighedOn = null,
        #[Assert\Valid]
        public array $entries = [],
    ) {
    }

    /** The sheet as the report shows it: its date of weighing (today when none is saved) and the rows' measurements. */
    public static function fromReport(Sf8Report $report): self
    {
        return new self($report->weighedOn, array_map(
            static fn (Sf8Row $row) => new NutritionEntry((int) $row->entry->enrollment->getId(), $row->weightKg, $row->heightM, $row->remarks),
            $report->rows,
        ));
    }

    /** @return list<MeasurementInput> */
    public function inputs(): array
    {
        return array_map(static fn (NutritionEntry $entry) => $entry->toInput(), $this->entries);
    }
}
