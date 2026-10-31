<?php

namespace App\CardSlip;

use App\Attendance\RosterEntry;
use App\Attendance\SchoolCalendar;
use App\Attendance\Sf2Roster;
use App\Entity\GradingPeriod;
use App\Entity\LearnerMark;
use App\Entity\Section;
use App\Repository\EnrollmentRepository;
use App\Repository\RollCallRepository;
use App\Service\SchoolClock;

/**
 * A section's attendance over a grading term, counted as SF2 counts its months: only the school
 * days a learner is on the roster, up to today; a school day without a roll call counts as present.
 */
class TermAttendance
{
    public function __construct(
        private readonly Sf2Roster $roster,
        private readonly SchoolCalendar $calendar,
        private readonly RollCallRepository $rollCalls,
        private readonly SchoolClock $clock,
        private readonly EnrollmentRepository $enrollments,
    ) {
    }

    /**
     * @return array<int, ?AttendanceTally> by enrollment id; null for everyone when the term has no
     *                                      start or end date; learners on none of the term's rosters are left out
     */
    public function forSection(Section $section, GradingPeriod $period): array
    {
        $start = $period->getStartDate()?->setTime(0, 0);
        $end = $period->getEndDate()?->setTime(0, 0);
        if (null === $start || null === $end) {
            $result = [];
            foreach ($this->enrollments->findClassList($section) as $enrollment) {
                $result[(int) $enrollment->getId()] = null;
            }

            return $result;
        }

        /** @var array<int, list<RosterEntry>> $entries by enrollment id */
        $entries = [];
        for ($month = $start->modify('first day of this month'); $month <= $end; $month = $month->modify('+1 month')) {
            foreach ($this->roster->forMonth($section, $month) as $entry) {
                $entries[(int) $entry->enrollment->getId()][] = $entry;
            }
        }

        $to = min($end, $this->clock->now()->setTime(0, 0));
        $days = $start <= $to ? $this->calendar->schoolDays($section->getSchool(), $section->getSchoolYear(), $start, $to) : [];
        $rollCalls = [] !== $days ? $this->rollCalls->findBetween($section, $start, $to) : [];

        $result = [];
        foreach ($entries as $id => $learnerEntries) {
            $schoolDays = $absent = $late = 0;
            foreach ($days as $day) {
                if (!self::onRoster($learnerEntries, $day)) {
                    continue;
                }
                ++$schoolDays;
                $mark = ($rollCalls[$day->format('Y-m-d')] ?? null)?->markFor($learnerEntries[0]->enrollment);
                match ($mark) {
                    LearnerMark::Absent => ++$absent,
                    LearnerMark::Late, LearnerMark::Cutting => ++$late,
                    null => null,
                };
            }
            $result[$id] = new AttendanceTally($schoolDays, $schoolDays - $absent, $absent, $late);
        }

        return $result;
    }

    /** @param list<RosterEntry> $entries */
    private static function onRoster(array $entries, \DateTimeImmutable $day): bool
    {
        foreach ($entries as $entry) {
            if ($entry->counts($day)) {
                return true;
            }
        }

        return false;
    }
}
