<?php

namespace App\Form;

use App\Entity\Grade;
use App\Grading\SheetLearner;

/** A class's grades in one subject for a grading period, by enrollment id; null for a learner not graded. */
class GradeSheet
{
    /** @param array<int, ?int> $grades by enrollment id */
    public function __construct(public array $grades = [])
    {
    }

    /**
     * The sheet's editable learners with their saved grades.
     *
     * @param list<SheetLearner> $learners
     * @param array<int, Grade>  $saved    by enrollment id
     */
    public static function of(array $learners, array $saved): self
    {
        $grades = [];
        foreach ($learners as $learner) {
            if ($learner->editable) {
                $id = (int) $learner->enrollment->getId();
                $grades[$id] = ($saved[$id] ?? null)?->getValue();
            }
        }

        return new self($grades);
    }
}
