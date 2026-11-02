<?php

namespace App\Grading;

use App\Entity\Enrollment;

/** A learner on a grade sheet; $status is "Dropped" or "Transferred out" for a row that can't be edited. */
final readonly class SheetLearner
{
    public function __construct(
        public Enrollment $enrollment,
        public string $name,
        public bool $male,
        public ?string $lrn,
        public bool $editable,
        public ?string $status,
    ) {
    }
}
