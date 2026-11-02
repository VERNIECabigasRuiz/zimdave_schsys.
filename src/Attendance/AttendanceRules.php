<?php

namespace App\Attendance;

use App\Entity\Section;
use App\Repository\RollCallRepository;
use App\Repository\Sf2SubmissionRepository;
use App\Service\SchoolClock;

/** When attendance may be taken and a month submitted. Who may do it is AttendanceVoter's question. */
class AttendanceRules
{
    public function __construct(
        private readonly SchoolCalendar $calendar,
        private readonly RollCallRepository $rollCalls,
        private readonly Sf2SubmissionRepository $submissions,
        private readonly SchoolClock $clock,
    ) {
    }

    /** Why attendance can't be taken for the section on $date, or null when it can. */
    public function refusalToTake(Section $section, \DateTimeImmutable $date): ?string
    {
        $date = $date->setTime(0, 0);
        $schoolYear = $section->getSchoolYear();
        $start = $schoolYear->getStartDate()?->setTime(0, 0);
        $end = $schoolYear->getEndDate()?->setTime(0, 0);

        return match (true) {
            (null !== $start && $date < $start) || (null !== $end && $date > $end) => 'That date is outside the school year.',
            (int) $date->format('N') > 5 => 'No classes on weekends.',
            null !== $dayOff = $this->calendar->dayOff($section->getSchool(), $date) => \sprintf('No classes: %s.', rtrim($dayOff->getReason(), '.')),
            $date > $this->today() => "You can't take attendance for a future date.",
            $this->isLocked($section, $date) => \sprintf('%s is submitted and locked.', $date->format('F Y')),
            default => null,
        };
    }

    public function isLocked(Section $section, \DateTimeImmutable $month): bool
    {
        return null !== $this->submissions->findOne($section, $month);
    }

    /** @return list<\DateTimeImmutable> the month's school days up to today that have no roll call */
    public function missingDays(Section $section, \DateTimeImmutable $month): array
    {
        $days = array_filter(
            $this->calendar->monthDays($section->getSchool(), $section->getSchoolYear(), $month),
            fn (\DateTimeImmutable $day) => $day <= $this->today(),
        );
        if ([] === $days) {
            return [];
        }
        $taken = $this->rollCalls->findBetween($section, reset($days), end($days));

        return array_values(array_filter($days, static fn (\DateTimeImmutable $day) => !isset($taken[$day->format('Y-m-d')])));
    }

    /** Whether the month's last school day (or the school year) is over, so its SF2 can be submitted. */
    public function monthHasEnded(Section $section, \DateTimeImmutable $month): bool
    {
        $today = $this->today();
        $end = $section->getSchoolYear()->getEndDate()?->setTime(0, 0);
        if (null !== $end && $end < $today) {
            return true;
        }
        $days = $this->calendar->monthDays($section->getSchool(), $section->getSchoolYear(), $month);
        $last = [] === $days ? $month->modify('last day of this month')->setTime(0, 0) : end($days);

        return $last < $today;
    }

    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }
}
