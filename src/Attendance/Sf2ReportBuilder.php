<?php

namespace App\Attendance;

use App\Entity\LearnerMark;
use App\Entity\RollCall;
use App\Entity\Section;
use App\Repository\RollCallRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolDayOffRepository;
use App\Repository\Sf2SubmissionRepository;
use App\Service\SchoolClock;

/**
 * Computes a section's SF2 for a month from its roll calls. A school day without a roll call
 * counts everyone present. In the current month the figures stop at today.
 */
class Sf2ReportBuilder
{
    /** A run of absences this long is flagged (DepEd: "5 consecutive days of absences"). */
    private const RUN = 5;

    public function __construct(
        private readonly Sf2Roster $roster,
        private readonly SchoolCalendar $calendar,
        private readonly RollCallRepository $rollCalls,
        private readonly Sf2SubmissionRepository $submissions,
        private readonly SchoolDayOffRepository $daysOff,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly AttendanceRules $rules,
        private readonly SchoolClock $clock,
    ) {
    }

    public function build(Section $section, \DateTimeImmutable $month): Sf2Report
    {
        $school = $section->getSchool();
        $schoolYear = $section->getSchoolYear();
        $first = $month->modify('first day of this month')->setTime(0, 0);
        $last = $first->modify('last day of this month');
        $today = $this->clock->now()->setTime(0, 0);

        $days = $this->calendar->monthDays($school, $schoolYear, $first);
        // the school days just before the month, so a run of absences can start there
        $before = \array_slice($this->calendar->schoolDays($school, $schoolYear, $first->modify('-31 days'), $first->modify('-1 day')), -(self::RUN - 1));
        $rollCalls = $this->rollCalls->findBetween($section, [] !== $before ? $before[0] : $first, $last);
        $reported = array_filter($days, static fn (\DateTimeImmutable $d) => $d <= $today);
        $entries = $this->roster->forMonth($section, $first);

        $male = $female = [];
        $present = [array_fill(0, \count($days), 0), array_fill(0, \count($days), 0)];
        $sums = ['late' => [0, 0], 'registered' => [0, 0], 'run' => [0, 0], 'dropped' => [0, 0], 'out' => [0, 0], 'in' => [0, 0]];
        $lastDay = [] !== $days ? end($days) : $last;
        foreach ($entries as $entry) {
            $g = $entry->male ? 0 : 1;
            $cells = [];
            $absences = $tardies = 0;
            foreach ($days as $i => $day) {
                if (!$entry->counts($day)) {
                    $cells[] = Sf2Row::NOT_COUNTED;
                    continue;
                }
                $mark = $this->markOn($rollCalls, $day, $entry);
                $cells[] = match ($mark) {
                    LearnerMark::Absent => Sf2Row::ABSENT,
                    LearnerMark::Late => Sf2Row::LATE,
                    LearnerMark::Cutting => Sf2Row::CUTTING,
                    null => Sf2Row::PRESENT,
                };
                if ($day > $today) {
                    continue;
                }
                if (LearnerMark::Absent === $mark) {
                    ++$absences;
                } else {
                    ++$present[$g][$i];
                    $tardies += null !== $mark ? 1 : 0;
                }
            }

            $fiveConsecutive = $this->hasRun($entry, $rollCalls, $before, $reported);
            $row = new Sf2Row(
                (int) $entry->enrollment->getId(),
                (int) $entry->enrollment->getLearner()->getId(),
                $entry->name,
                $entry->male,
                $cells,
                $absences,
                $tardies,
                $entry->remark,
                $fiveConsecutive,
                $entry->undatedDrop,
            );
            if ($entry->male) {
                $male[] = $row;
            } else {
                $female[] = $row;
            }

            $sums['late'][$g] += $entry->lateEnrollment && $entry->from >= $first ? 1 : 0;
            $sums['registered'][$g] += $entry->counts($lastDay) ? 1 : 0;
            $sums['run'][$g] += $fiveConsecutive ? 1 : 0;
            $leftOn = $entry->until?->modify('+1 day');
            $sums['dropped'][$g] += $entry->dropped && null !== $leftOn && $leftOn >= $first && $leftOn <= $last ? 1 : 0;
            $sums['out'][$g] += $entry->transferredOut && null !== $leftOn && $leftOn >= $first && $leftOn <= $last ? 1 : 0;
            $sums['in'][$g] += $entry->transferredIn && $entry->from >= $first ? 1 : 0;
        }

        $daysOfClasses = \count($reported);
        $perDay = static fn (array $counts) => array_map(static fn (int $n, \DateTimeImmutable $d) => $d <= $today ? $n : null, $counts, $days);
        $adaOf = static fn (int $total) => $daysOfClasses > 0 ? round($total / $daysOfClasses, 2) : null;
        $ada = [$adaOf(array_sum($present[0])), $adaOf(array_sum($present[1])), $adaOf(array_sum($present[0]) + array_sum($present[1]))];
        $registered = self::withTotal($sums['registered']);
        $atCutOff = $this->enrolmentAtCutOff($section, $last);

        $summary = new Sf2Summary(
            daysOfClasses: $daysOfClasses,
            enrolmentAtCutOff: $atCutOff,
            lateEnrollment: self::withTotal($sums['late']),
            registered: $registered,
            percentEnrolment: array_map(static fn (int $r, int $c) => $c > 0 ? round($r / $c * 100, 2) : null, $registered, $atCutOff ?? [0, 0, 0]),
            ada: $ada,
            percentAttendance: array_map(static fn (?float $a, int $r) => null !== $a && $r > 0 ? round($a / $r * 100, 2) : null, $ada, $registered),
            consecutiveAbsent: self::withTotal($sums['run']),
            dropped: self::withTotal($sums['dropped']),
            transferredOut: self::withTotal($sums['out']),
            transferredIn: self::withTotal($sums['in']),
        );

        $startYear = (int) ($schoolYear->getStartDate()?->format('Y') ?? substr($schoolYear->getName(), 0, 4));
        $principal = $this->assignments->findPrincipal($school, $schoolYear)?->getUser();
        $adviser = $section->getAdviser();

        return new Sf2Report(
            schoolId: $school->getSchoolId(),
            schoolName: $school->getName(),
            schoolYear: $startYear.' - '.($startYear + 1),
            month: $first,
            gradeLevel: $section->getGradeLevel()?->label() ?? '',
            section: mb_strtoupper($section->getName()),
            days: $days,
            taken: array_map(static fn (\DateTimeImmutable $d) => isset($rollCalls[$d->format('Y-m-d')]), $days),
            male: $male,
            female: $female,
            malePresent: $perDay($present[0]),
            femalePresent: $perDay($present[1]),
            summary: $summary,
            adviserName: null !== $adviser ? mb_strtoupper($adviser->getFullName()) : null,
            schoolHeadName: null !== $principal ? mb_strtoupper($principal->getFullName()) : null,
            submission: $this->submissions->findOne($section, $first),
            missingDays: $this->rules->missingDays($section, $first),
            daysOff: array_values(array_map(static fn ($d) => $d->getDate()->format('M j').' – '.$d->getReason(), $this->daysOff->findBetween($school, $first, $last))),
        );
    }

    /** @param array<string, RollCall> $rollCalls */
    private function markOn(array $rollCalls, \DateTimeImmutable $day, RosterEntry $entry): ?LearnerMark
    {
        return ($rollCalls[$day->format('Y-m-d')] ?? null)?->markFor($entry->enrollment);
    }

    /**
     * Whether the learner was absent RUN school days in a row, ending on a passed day of the month.
     * Weekends and days off neither break nor extend a run.
     *
     * @param array<string, RollCall>  $rollCalls
     * @param list<\DateTimeImmutable> $before    the school days just before the month
     * @param array<\DateTimeImmutable> $reported the month's school days up to today
     */
    private function hasRun(RosterEntry $entry, array $rollCalls, array $before, array $reported): bool
    {
        $run = 0;
        foreach ([...$before, ...$reported] as $i => $day) {
            $absent = $entry->counts($day) && LearnerMark::Absent === $this->markOn($rollCalls, $day, $entry);
            $run = $absent ? $run + 1 : 0;
            if ($run >= self::RUN && $i >= \count($before)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{int, int, int}|null learners counted on the cut-off date, by sex; null when $last is before it */
    private function enrolmentAtCutOff(Section $section, \DateTimeImmutable $last): ?array
    {
        $cutOff = $this->calendar->cutOff($section->getSchoolYear());
        if ($last < $cutOff) {
            return null;
        }

        $counts = [0, 0];
        foreach ($this->roster->forMonth($section, $cutOff) as $entry) {
            if ($entry->counts($cutOff)) {
                ++$counts[$entry->male ? 0 : 1];
            }
        }

        return self::withTotal($counts);
    }

    /**
     * @param array{int, int} $pair
     *
     * @return array{int, int, int}
     */
    private static function withTotal(array $pair): array
    {
        return [$pair[0], $pair[1], $pair[0] + $pair[1]];
    }
}
