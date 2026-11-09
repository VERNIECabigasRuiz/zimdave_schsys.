<?php

declare(strict_types=1);

namespace App\Attendance\Application\Api;

use App\Attendance\Application\Service\AttendanceStatistics;
use App\Attendance\Domain\Model\LearnerMark;
use App\Attendance\Domain\Model\Repository\RollCallRepositoryInterface;
use App\Classes\Domain\Model\Section;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Learners\Application\Api\RosterEntry;
use App\Learners\Application\Api\SectionRosterInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

final class AttendanceDirectory implements AttendanceDirectoryInterface
{
    public function __construct(
        private readonly SectionRosterInterface $roster,
        private readonly SchoolDirectoryInterface $schools,
        private readonly RollCallRepositoryInterface $rollCalls,
        private readonly AttendanceStatistics $statistics,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    public function tallies(Section $section, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $start = $start->setTime(0, 0);
        $end = $end->setTime(0, 0);

        /** @var array<int, list<RosterEntry>> $entries by enrollment id */
        $entries = [];
        for ($month = $start->modify('first day of this month'); $month <= $end; $month = $month->modify('+1 month')) {
            foreach ($this->roster->forMonth($section, $month) as $entry) {
                $entries[(int) $entry->enrollment->getId()][] = $entry;
            }
        }

        $to = min($end, $this->clock->now()->setTime(0, 0));
        $days = $start <= $to
            ? $this->schools->schoolDays($section->getSchool(), $section->getSchoolYear(), $start, $to)
            : [];
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

    public function rollCallTaken(Section $section, \DateTimeImmutable $day): bool
    {
        return null !== $this->rollCalls->findOne($section, $day);
    }

    public function schoolToday(School $school, SchoolYear $schoolYear): array
    {
        return $this->statistics->today($school, $schoolYear);
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
