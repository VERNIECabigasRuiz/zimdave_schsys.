<?php

namespace App\Nutrition;

use App\Entity\Section;
use App\Repository\NutritionAssessmentRepository;
use App\Repository\Sf8SubmissionRepository;
use App\Service\SchoolClock;

/** When a section may be weighed. Who may do it is NutritionVoter's question. */
class NutritionRules
{
    public function __construct(
        private readonly NutritionAssessmentRepository $assessments,
        private readonly Sf8SubmissionRepository $submissions,
        private readonly SchoolClock $clock,
    ) {
    }

    public function isLocked(Section $section, NutritionPeriod $period): bool
    {
        return null !== $this->submissions->findFor($section, $period);
    }

    /** Why the section can't be weighed on $weighedOn for the period, or null when it can. */
    public function refusalForDate(Section $section, NutritionPeriod $period, \DateTimeImmutable $weighedOn): ?string
    {
        $weighedOn = $weighedOn->setTime(0, 0);
        $schoolYear = $section->getSchoolYear();
        $start = $schoolYear->getStartDate()?->setTime(0, 0);
        $end = $schoolYear->getEndDate()?->setTime(0, 0);

        if ((null !== $start && $weighedOn < $start) || (null !== $end && $weighedOn > $end)) {
            return \sprintf('Weigh inside the school year (%s).', match (true) {
                null !== $start && null !== $end => $start->format('M j, Y').' – '.$end->format('M j, Y'),
                null !== $start => 'from '.$start->format('M j, Y'),
                default => 'until '.$end?->format('M j, Y'),
            });
        }
        if ($weighedOn > $this->clock->now()->setTime(0, 0)) {
            return "The date of weighing can't be in the future.";
        }

        if (NutritionPeriod::Endline === $period) {
            $baseline = $this->assessments->findFor($section, NutritionPeriod::Baseline)?->getWeighedOn()->setTime(0, 0);
            if (null !== $baseline && $weighedOn <= $baseline) {
                return \sprintf('The endline must be after the baseline (%s).', $baseline->format('M j, Y'));
            }
        } else {
            $endline = $this->assessments->findFor($section, NutritionPeriod::Endline)?->getWeighedOn()->setTime(0, 0);
            if (null !== $endline && $weighedOn >= $endline) {
                return \sprintf('The baseline must be before the endline (%s).', $endline->format('M j, Y'));
            }
        }

        return null;
    }
}
