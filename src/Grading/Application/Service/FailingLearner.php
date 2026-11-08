<?php

declare(strict_types=1);

namespace App\Grading\Application\Service;

use App\Classes\Domain\Model\Section;

/** A learner whose general average for a term is failing, for the principal's list. */
final readonly class FailingLearner
{
    /**
     * @param array<string, int> $failedSubjects top-level subject label => grade below 75
     */
    public function __construct(
        public Section $section,
        public SheetLearner $learner,
        public float $average,
        public array $failedSubjects,
    ) {
    }
}
