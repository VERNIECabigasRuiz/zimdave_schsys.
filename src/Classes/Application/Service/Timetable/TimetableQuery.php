<?php

declare(strict_types=1);

namespace App\Classes\Application\Service\Timetable;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\ClassPeriod;
use App\Classes\Domain\Model\Repository\ClassPeriodRepositoryInterface;
use App\Classes\Domain\Model\Repository\ScheduleEntryRepositoryInterface;
use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use App\Classes\Domain\Model\Repository\TeachingLoadRepositoryInterface;
use App\Classes\Domain\Model\ScheduleEntry;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Weekday;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/**
 * Read side of the timetable: the data the timetable pages render. Never writes.
 *
 * @phpstan-type PeriodRow array{id: ?int, label: string, number: ?int, isBreak: bool, startsAt: ?string,
 *     endsAt: ?string}
 * @phpstan-type Day array{value: int, label: string}
 */
class TimetableQuery
{
    public function __construct(
        private readonly ScheduleEntryRepositoryInterface $entries,
        private readonly TeachingLoadRepositoryInterface $loads,
        private readonly SubjectRepositoryInterface $subjects,
        private readonly ClassPeriodRepositoryInterface $periods,
        private readonly SchoolDirectoryInterface $schools,
    ) {
    }

    /**
     * 0 = full year (Kinder to Grade 10); 1 and 2 = the semesters of Grades 11-12.
     *
     * @return list<int>
     */
    public function termsFor(Section $section): array
    {
        return $section->getGradeLevel()?->isSeniorHigh() ? [1, 2] : [0];
    }

    /**
     * @return array{term: int, terms: list<int>, days: list<Day>, periods: list<PeriodRow>,
     *     teachers: list<array{id: ?int, name: string}>,
     *     cards: list<array{subjectId: ?int, name: string, teacherId: ?int, teacherName: ?string, used: int,
     *         perWeek: ?int}>,
     *     cells: list<array{entryId: ?int, day: int, periodId: ?int, subjectId: ?int, name: string,
     *         teacherName: string}>,
     *     busy: array<array-key, array<string, string>>}
     */
    public function sectionState(Section $section, int $term): array
    {
        $school = $section->getSchool();
        $schoolYear = $section->getSchoolYear();

        $teachers = [];
        foreach ($this->schools->currentTeachersOf($school, $schoolYear) as $teacher) {
            $teachers[] = ['id' => $teacher->getId(), 'name' => $teacher->getFullName()];
        }

        $used = [];
        $cells = [];
        foreach ($this->entries->findForSection($section, $term) as $entry) {
            $subject = $entry->getLoad()->getSubject();
            $used[$subject->getId()] = ($used[$subject->getId()] ?? 0) + 1;
            $cells[] = [
                'entryId' => $entry->getId(),
                'day' => $entry->getDay()->value,
                'periodId' => $entry->getPeriod()->getId(),
                'subjectId' => $subject->getId(),
                'name' => $subject->getName(),
                'teacherName' => $entry->getTeacher()->getFullName(),
            ];
        }

        $teacherOf = [];
        foreach ($this->loads->findForSection($section) as $load) {
            $teacherOf[$load->getSubject()->getId()] = $load->getTeacher();
        }
        $senior = $section->getGradeLevel()?->isSeniorHigh() ?? false;
        $cards = [];
        foreach ($this->subjects->findForSection($section) as $subject) {
            if (!$subject->isLoadable() || ($senior && $subject->getSemester() !== $term)) {
                continue;
            }
            $teacher = $teacherOf[$subject->getId()] ?? null;
            $cards[] = [
                'subjectId' => $subject->getId(),
                'name' => $subject->getName(),
                'teacherId' => $teacher?->getId(),
                'teacherName' => $teacher?->getFullName(),
                'used' => $used[$subject->getId()] ?? 0,
                'perWeek' => $subject->getMeetingsPerWeek(),
            ];
        }

        $busy = [];
        foreach ($this->entries->findBusy($school, $schoolYear, $term, $section) as $entry) {
            $busy[(string) $entry->getTeacher()->getId()][$entry->getDay()->value . '-' . $entry->getPeriod()->getId()]
                = $entry->getLoad()->getSubject()->getName() . ', ' . $entry->getSection()->getLabel();
        }

        return [
            'term' => $term,
            'terms' => $this->termsFor($section),
            'days' => $this->days(),
            'periods' => $this->periodRows($this->periods->findForSchool($school, $schoolYear)),
            'teachers' => $teachers,
            'cards' => $cards,
            'cells' => $cells,
            'busy' => $busy,
        ];
    }

    /**
     * @return array{periods: list<PeriodRow>, days: list<Day>,
     *     cells: list<array{day: int, periodId: ?int, name: string, sectionLabel: string}>,
     *     loads: list<array{subjectName: string, sectionLabel: string, perWeek: ?int, semester: ?int}>,
     *     totalPerWeek: int}
     */
    public function teacherWeek(User $teacher, SchoolYear $schoolYear, int $term): array
    {
        $entries = array_values(array_filter(
            $this->entries->findForTeacher($teacher, $schoolYear),
            static fn (ScheduleEntry $e) => 0 === $term || 0 === $e->getTerm() || $e->getTerm() === $term,
        ));
        $cells = array_map(static fn (ScheduleEntry $e) => [
            'day' => $e->getDay()->value,
            'periodId' => $e->getPeriod()->getId(),
            'name' => $e->getLoad()->getSubject()->getName(),
            'sectionLabel' => $e->getSection()->getLabel(),
        ], $entries);

        $loads = [];
        $school = null;
        foreach ($this->loads->findForTeacher($teacher, $schoolYear) as $load) {
            $subject = $load->getSubject();
            $school ??= $load->getSection()->getSchool();
            if (0 !== $term && null !== $subject->getSemester() && $subject->getSemester() !== $term) {
                continue;
            }
            $loads[] = [
                'subjectName' => $subject->getName(),
                'sectionLabel' => $load->getSection()->getLabel(),
                'perWeek' => $subject->getMeetingsPerWeek(),
                'semester' => $subject->getSemester(),
            ];
        }

        return [
            'periods' => null === $school ? [] : $this->periodRows($this->periods->findForSchool($school, $schoolYear)),
            'days' => $this->days(),
            'cells' => $cells,
            'loads' => $loads,
            'totalPerWeek' => \count($entries),
        ];
    }

    /**
     * What is happening at this moment in a school: the classes running, the teachers with none, or why nothing is.
     *
     * @return array{state: string, period: array{label: string, timeRange: string}|null,
     *     classes: list<array{teacherName: string, subjectName: string, sectionLabel: string}>,
     *     free: list<string>, next: array{label: string, startsAt: string}|null}
     */
    public function now(School $school, SchoolYear $schoolYear, \DateTimeImmutable $at): array
    {
        $result = ['state' => 'no_periods', 'period' => null, 'classes' => [], 'free' => [], 'next' => null];
        $periods = $this->periods->findForSchool($school, $schoolYear);
        if ([] === $periods) {
            return $result;
        }
        $day = Weekday::fromDate($at);
        if (null === $day) {
            return ['state' => 'weekend'] + $result;
        }

        $numbers = $this->periods->numbering($periods);
        $time = $at->format('H:i:s');
        $current = null;
        $next = null;
        foreach ($periods as $period) {
            if ($this->contains($period, $time)) {
                $current = $period;
            } elseif (null === $next && !$period->isBreak() && self::time($period->getStartsAt(), 'H:i:s') > $time) {
                $next = $period;
            }
        }
        $result['next'] = null === $next ? null : [
            'label' => 'Period ' . $numbers[(int) $next->getId()],
            'startsAt' => self::time($next->getStartsAt()),
        ];

        if (null === $current) {
            $result['state'] = $time < self::time($periods[0]->getStartsAt(), 'H:i:s') ? 'before'
                : ($time >= self::time(end($periods)->getEndsAt(), 'H:i:s') ? 'after' : 'break');

            return $result;
        }

        $result['period'] = [
            'label' => $current->isBreak()
                ? (string) $current->getBreakLabel()
                : 'Period ' . $numbers[(int) $current->getId()],
            'timeRange' => self::time($current->getStartsAt()) . '–' . self::time($current->getEndsAt()),
        ];
        if ($current->isBreak()) {
            $result['state'] = 'break';

            return $result;
        }

        $term = $this->termAt($schoolYear, $at);
        $busy = [];
        foreach ($this->entries->findAt($school, $schoolYear, $day, $current) as $entry) {
            if (!$this->countsIn($entry, $term)) {
                continue;
            }
            $busy[$entry->getTeacher()->getId()] = true;
            $result['classes'][] = [
                'teacherName' => $entry->getTeacher()->getFullName(),
                'subjectName' => $entry->getLoad()->getSubject()->getName(),
                'sectionLabel' => $entry->getSection()->getLabel(),
            ];
        }
        foreach ($this->schools->currentTeachersOf($school, $schoolYear) as $teacher) {
            if (!isset($busy[$teacher->getId()])) {
                $result['free'][] = $teacher->getFullName();
            }
        }
        $result['state'] = 'class';

        return $result;
    }

    /**
     * A teacher's own view of the moment: the class now and the next one today.
     *
     * @return array{state: string,
     *     current: array{subjectName: string, sectionLabel: string, endsAt: string}|null,
     *     next: array{subjectName: string, sectionLabel: string, startsAt: string}|null}
     */
    public function nowForTeacher(User $teacher, SchoolYear $schoolYear, \DateTimeImmutable $at): array
    {
        $result = ['state' => 'no_periods', 'current' => null, 'next' => null];
        $school = $this->schools->schoolOf($teacher, $schoolYear, User::ROLE_TEACHER);
        $periods = null === $school ? [] : $this->periods->findForSchool($school, $schoolYear);
        if ([] === $periods) {
            return $result;
        }
        $day = Weekday::fromDate($at);
        if (null === $day) {
            return ['state' => 'weekend'] + $result;
        }

        $term = $this->termAt($schoolYear, $at);
        $time = $at->format('H:i:s');
        $today = array_values(array_filter(
            $this->entries->findForTeacherOn($teacher, $schoolYear, $day),
            fn (ScheduleEntry $e) => $this->countsIn($e, $term),
        ));
        usort(
            $today,
            static fn (ScheduleEntry $a, ScheduleEntry $b) => $a->getPeriod()->getStartsAt()
                <=> $b->getPeriod()->getStartsAt(),
        );

        $current = null;
        $next = null;
        foreach ($today as $entry) {
            if ($this->contains($entry->getPeriod(), $time)) {
                $current = $entry;
            } elseif (null === $next && self::time($entry->getPeriod()->getStartsAt(), 'H:i:s') > $time) {
                $next = $entry;
            }
        }
        $result['current'] = null === $current ? null : [
            'subjectName' => $current->getLoad()->getSubject()->getName(),
            'sectionLabel' => $current->getSection()->getLabel(),
            'endsAt' => self::time($current->getPeriod()->getEndsAt()),
        ];
        $result['next'] = null === $next ? null : [
            'subjectName' => $next->getLoad()->getSubject()->getName(),
            'sectionLabel' => $next->getSection()->getLabel(),
            'startsAt' => self::time($next->getPeriod()->getStartsAt()),
        ];

        if (null !== $current) {
            $result['state'] = 'class';
        } elseif (null === $next) {
            $result['state'] = 'done';
        } elseif ($time < self::time($periods[0]->getStartsAt(), 'H:i:s')) {
            $result['state'] = 'before';
        } else {
            $inBreak = false;
            foreach ($periods as $period) {
                $inBreak = $inBreak || ($period->isBreak() && $this->contains($period, $time));
            }
            $result['state'] = $inBreak ? 'break' : 'free';
        }

        return $result;
    }

    private function contains(ClassPeriod $period, string $time): bool
    {
        return self::time($period->getStartsAt(), 'H:i:s') <= $time
            && $time < self::time($period->getEndsAt(), 'H:i:s');
    }

    /** A saved period's start or end time, which is always set. */
    private static function time(?\DateTimeImmutable $time, string $format = 'H:i'): string
    {
        return (string) $time?->format($format);
    }

    /** Semester 1 before the school year's midpoint, semester 2 from then on. */
    private function termAt(SchoolYear $schoolYear, \DateTimeImmutable $at): int
    {
        $start = (int) $schoolYear->getStartDate()?->getTimestamp();
        $midpoint = $start + intdiv((int) $schoolYear->getEndDate()?->getTimestamp() - $start, 2);

        return $at->getTimestamp() < $midpoint ? 1 : 2;
    }

    private function countsIn(ScheduleEntry $entry, int $term): bool
    {
        return 0 === $entry->getTerm() || $entry->getTerm() === $term;
    }

    /** @return list<Day> */
    private function days(): array
    {
        return array_map(static fn (Weekday $d) => ['value' => $d->value, 'label' => $d->short()], Weekday::cases());
    }

    /**
     * @param list<ClassPeriod> $periods in time order
     *
     * @return list<PeriodRow>
     */
    private function periodRows(array $periods): array
    {
        $numbers = $this->periods->numbering($periods);

        return array_map(static fn (ClassPeriod $p) => [
            'id' => $p->getId(),
            'label' => $p->isBreak() ? (string) $p->getBreakLabel() : 'Period ' . $numbers[(int) $p->getId()],
            'number' => $numbers[$p->getId()] ?? null,
            'isBreak' => $p->isBreak(),
            'startsAt' => $p->getStartsAt()?->format('H:i'),
            'endsAt' => $p->getEndsAt()?->format('H:i'),
        ], $periods);
    }
}
