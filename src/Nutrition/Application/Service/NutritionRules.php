<?php

declare(strict_types=1);

namespace App\Nutrition\Application\Service;

use App\Classes\Domain\Model\Section;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Nutrition\Domain\Model\Repository\NutritionAssessmentRepositoryInterface;
use App\Nutrition\Domain\Model\Repository\Sf8SubmissionRepositoryInterface;

/**
 * When a section may be weighed. Who may do it is NutritionPolicy's question. It reads what another
 * request may have changed fresh, so a use case sees it under its lock.
 */
class NutritionRules
{
    public function __construct(
        private readonly NutritionAssessmentRepositoryInterface $assessments,
        private readonly Sf8SubmissionRepositoryInterface $submissions,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    public function isLocked(Section $section, NutritionPeriod $period): bool
    {
        return null !== $this->submissions->findFresh($section, $period);
    }

    /** Why the section can't be weighed on $weighedOn for the period, or null when it can. */
    public function refusalForDate(Section $section, NutritionPeriod $period, \DateTimeImmutable $weighedOn): ?string
    {
        $weighedOn = $weighedOn->setTime(0, 0);
        $schoolYear = $section->getSchoolYear();
        $start = $schoolYear->getStartDate()?->setTime(0, 0);
        $end = $schoolYear->getEndDate()?->setTime(0, 0);
        if ((null !== $start && $weighedOn < $start) || (null !== $end && $weighedOn > $end)) {
            if (null !== $start && null !== $end) {
                $range = $start->format('M j, Y') . ' – ' . $end->format('M j, Y');
            } elseif (null !== $start) {
                $range = 'from ' . $start->format('M j, Y');
            } else {
                $range = 'until ' . $end?->format('M j, Y');
            }

            return \sprintf('Weigh inside the school year (%s).', $range);
        }
        if ($weighedOn > $this->clock->now()->setTime(0, 0)) {
            return "The date of weighing can't be in the future.";
        }
        if (NutritionPeriod::Endline === $period) {
            $baseline = $this->weighedOn($section, NutritionPeriod::Baseline);
            if (null !== $baseline && $weighedOn <= $baseline) {
                return \sprintf('The endline must be after the baseline (%s).', $baseline->format('M j, Y'));
            }
        } else {
            $endline = $this->weighedOn($section, NutritionPeriod::Endline);
            if (null !== $endline && $weighedOn >= $endline) {
                return \sprintf('The baseline must be before the endline (%s).', $endline->format('M j, Y'));
            }
        }

        return null;
    }

    private function weighedOn(Section $section, NutritionPeriod $period): ?\DateTimeImmutable
    {
        return $this->assessments->findFresh($section, $period)?->getWeighedOn()->setTime(0, 0);
    }
}
