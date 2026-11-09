<?php

declare(strict_types=1);

namespace App\Grading\Application\Service;

use App\Learners\Domain\Model\Enrollment;

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
