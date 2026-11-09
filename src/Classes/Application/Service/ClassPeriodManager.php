<?php

declare(strict_types=1);

namespace App\Classes\Application\Service;

use App\Classes\Application\UseCase\Command\ClassPeriodDetails;
use App\Classes\Domain\Exception\InUseException;
use App\Classes\Domain\Model\ClassPeriod;
use App\Classes\Domain\Model\Repository\ClassPeriodRepositoryInterface;
use App\Classes\Domain\Model\Repository\ScheduleEntryRepositoryInterface;
use App\Classes\Domain\Service\Period\ClassPeriodDay;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\PlanLimitException;
use App\Schools\Domain\Model\School;

/**
 * Creates, changes and removes the slots of a school's day. Every write is refused
 * while the school's plan is blocked (inactive school or expired plan = view-only).
 * Each change runs under the school's timetable lock, taken by its use case, so a period
 * can't become a break or be removed while a class is being plotted in it.
 */
class ClassPeriodManager
{
    public const LOCK_PREFIX = 'school-timetable-';
    public const BUSY = 'Another change is being saved. Please try again.';

    public function __construct(
        private readonly ClassPeriodRepositoryInterface $periods,
        private readonly SchoolDirectoryInterface $schools,
        private readonly ActivityLoggerInterface $activityLogger,
        private readonly ScheduleEntryRepositoryInterface $entries,
    ) {
    }

    /** Why the periods of this school can't be changed now, or null. */
    public function refusal(School $school): ?string
    {
        return $this->schools->blockedReason($school);
    }

    /**
     * Saves a new (already validated) period and re-sorts the day by start time.
     *
     * @return string the period's label after the change
     */
    public function create(ClassPeriod $period): string
    {
        $this->assertWritable($period->getSchool());
        $this->periods->add($period);
        $label = ClassPeriodDay::label($period, $this->resort($period, null));

        $this->activityLogger->log('Added class period ' . $label);

        return $label;
    }

    /**
     * Applies an (already validated) edit and re-sorts the day by start time. Turning a period
     * with classes into a break would strand them, so that is refused before anything changes.
     *
     * @return string the period's label after the change
     */
    public function update(ClassPeriod $period, ClassPeriodDetails $details): string
    {
        $this->assertWritable($period->getSchool());
        if ($details->isBreak && !$period->isBreak()) {
            $this->assertNotInUse($period);
        }
        $period->setStartsAt($details->startsAt)
            ->setEndsAt($details->endsAt)
            ->setBreakLabel($details->isBreak ? $details->breakLabel : null);
        $label = ClassPeriodDay::label($period, $this->resort($period, null));

        $this->activityLogger->log('Updated class period ' . $label);

        return $label;
    }

    /** @return string the label the period had */
    public function delete(ClassPeriod $period): string
    {
        $this->assertWritable($period->getSchool());
        $this->assertNotInUse($period);
        $label = $this->label($period);
        $this->activityLogger->log('Deleted class period ' . $label);
        $this->periods->remove($period);
        $this->resort(null, $period);

        return $label;
    }

    /** "Period 3", or the break label, as the school's day stands now. */
    public function label(ClassPeriod $period): string
    {
        return ClassPeriodDay::label(
            $period,
            $this->periods->findForSchool($period->getSchool(), $period->getSchoolYear()),
        );
    }

    /**
     * Numbers the day's positions by start time, so lists and timetables read in school-day order.
     *
     * @return list<ClassPeriod> the day after the change
     */
    private function resort(?ClassPeriod $changed, ?ClassPeriod $removed): array
    {
        $any = $changed ?? $removed;
        \assert(null !== $any);
        $day = array_values(array_filter(
            $this->periods->findForSchool($any->getSchool(), $any->getSchoolYear()),
            static fn (ClassPeriod $period) => $period !== $removed && $period !== $changed,
        ));
        if (null !== $changed) {
            $day[] = $changed;
        }
        $day = ClassPeriodDay::sorted($day);
        foreach ($day as $i => $period) {
            $period->setPosition($i + 1);
        }

        return $day;
    }

    private function assertNotInUse(ClassPeriod $period): void
    {
        if (0 === $sections = $this->entries->countForPeriod($period)) {
            return;
        }
        throw new InUseException(\sprintf(
            '%s has classes in %d %s. Remove them first.',
            $this->label($period),
            $sections,
            1 === $sections ? 'section' : 'sections',
        ));
    }

    private function assertWritable(School $school): void
    {
        if (null !== $refusal = $this->refusal($school)) {
            throw new PlanLimitException($refusal);
        }
    }
}
