<?php

namespace App\Attendance;

use App\Entity\School;
use App\Entity\SchoolDayOff;
use App\Entity\SchoolYear;
use App\Repository\SchoolDayOffRepository;

/** Which dates are school days: Monday to Friday within the school year, less the school's days off. */
class SchoolCalendar
{
    public function __construct(private readonly SchoolDayOffRepository $daysOff)
    {
    }

    /** @return list<\DateTimeImmutable> the school days from $from to $to, both included */
    public function schoolDays(School $school, SchoolYear $schoolYear, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        if (null !== $start = $schoolYear->getStartDate()) {
            $from = max($from, $start->setTime(0, 0));
        }
        if (null !== $end = $schoolYear->getEndDate()) {
            $to = min($to, $end->setTime(0, 0));
        }
        if ($from > $to) {
            return [];
        }

        $daysOff = $this->daysOff->findBetween($school, $from, $to);
        $days = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') <= 5 && !isset($daysOff[$day->format('Y-m-d')])) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /** @return list<\DateTimeImmutable> the school days of the calendar month $month falls in */
    public function monthDays(School $school, SchoolYear $schoolYear, \DateTimeImmutable $month): array
    {
        $first = $month->modify('first day of this month')->setTime(0, 0);

        return $this->schoolDays($school, $schoolYear, $first, $first->modify('last day of this month'));
    }

    public function dayOff(School $school, \DateTimeImmutable $date): ?SchoolDayOff
    {
        $date = $date->setTime(0, 0);

        return $this->daysOff->findBetween($school, $date, $date)[$date->format('Y-m-d')] ?? null;
    }

    public function isSchoolDay(School $school, SchoolYear $schoolYear, \DateTimeImmutable $date): bool
    {
        return [] !== $this->schoolDays($school, $schoolYear, $date, $date);
    }

    /** The enrolment cut-off: the first Friday of the school calendar ("1st Friday of June"). */
    public function cutOff(SchoolYear $schoolYear): \DateTimeImmutable
    {
        $start = $schoolYear->getStartDate()?->setTime(0, 0)
            ?? new \DateTimeImmutable(substr($schoolYear->getName(), 0, 4).'-06-01');

        return 5 === (int) $start->format('N') ? $start : $start->modify('next friday');
    }
}
