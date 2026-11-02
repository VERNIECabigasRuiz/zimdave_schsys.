<?php

namespace App\Form;

use App\Grading\SheetLearner;

/** A teacher's change request as typed: the corrected grade by enrollment id (null to leave as is) and why. */
class GradeChange
{
    /** @param array<int, ?int> $grades by enrollment id */
    public function __construct(public array $grades = [], public string $reason = '')
    {
    }

    /**
     * A blank correction for each editable learner of the sheet.
     *
     * @param list<SheetLearner> $learners
     */
    public static function of(array $learners): self
    {
        $grades = [];
        foreach ($learners as $learner) {
            if ($learner->editable) {
                $grades[(int) $learner->enrollment->getId()] = null;
            }
        }

        return new self($grades);
    }
}
