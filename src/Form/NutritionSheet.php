<?php

namespace App\Form;

use App\Nutrition\MeasurementInput;
use App\Nutrition\Sf8Report;
use Symfony\Component\Validator\Constraints as Assert;

/** The adviser's weighing sheet for a period: the date of weighing and a line per learner. */
class NutritionSheet
{
    /** @param array<int, NutritionEntry> $entries by enrollment id */
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
        $entries = [];
        foreach ($report->rows as $row) {
            $id = (int) $row->entry->enrollment->getId();
            $entries[$id] = new NutritionEntry($id, $row->weightKg, $row->heightM, $row->remarks);
        }

        return new self($report->weighedOn, $entries);
    }

    /** @return list<MeasurementInput> */
    public function inputs(): array
    {
        return array_values(array_map(static fn (NutritionEntry $entry) => $entry->toInput(), $this->entries));
    }
}
