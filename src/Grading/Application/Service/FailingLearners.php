<?php

declare(strict_types=1);

namespace App\Grading\Application\Service;

use App\Classes\Application\Api\SectionDirectoryInterface;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/**
 * The school's learners who failed the general average of a term, from the same grade grid and average as the
 * card slip. A learner with a subject not graded yet has no average, so isn't listed; nor is one who
 * transferred out of the section.
 */
class FailingLearners
{
    public function __construct(
        private readonly SectionDirectoryInterface $sections,
        private readonly SectionGradeGrid $grid,
    ) {
    }

    /** @return list<FailingLearner> by grade level and section, then the learner's name */
    public function forSchool(School $school, SchoolYear $schoolYear, GradingPeriod $period): array
    {
        $failing = [];
        foreach ($this->sections->sectionsOf($school, $schoolYear) as $section) {
            $grid = $this->grid->build($section, $period);
            $componentKeys = GeneralAverage::componentKeys($this->sections->subjectsOf($section));
            $inSection = [];
            foreach ($grid['rows'] as $row) {
                if (!$row['learner']->editable) {
                    continue;
                }
                $grades = GeneralAverage::topLevelGrades($grid['columns'], $row['cells'], $componentKeys);
                $average = GeneralAverage::of($grades);
                if (null === $average || !GeneralAverage::isFailing($average)) {
                    continue;
                }
                $inSection[] = new FailingLearner(
                    $section,
                    $row['learner'],
                    $average,
                    array_filter(
                        $grades,
                        static fn (?int $grade): bool => null !== $grade && $grade < GeneralAverage::PASSING,
                    ),
                );
            }
            usort(
                $inSection,
                static fn (FailingLearner $a, FailingLearner $b): int => strcmp($a->learner->name, $b->learner->name),
            );
            array_push($failing, ...$inSection);
        }

        return $failing;
    }
}
