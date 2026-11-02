<?php

namespace App\Service;

use App\Entity\ClassPeriod;
use App\Entity\ScheduleEntry;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\User;
use App\Entity\Weekday;
use App\Repository\ClassPeriodRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SubjectRepository;
use App\Repository\TeachingLoadRepository;

/**
 * Read side of the timetable: the data the timetable pages render. Never writes.
 */
class TimetableQuery
{
    public function __construct(
        private readonly ScheduleEntryRepository $entries,
        private readonly TeachingLoadRepository $loads,
        private readonly SubjectRepository $subjects,
        private readonly ClassPeriodRepository $periods,
        private readonly SchoolAssignmentRepository $assignments,
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

    /** @return array<string, mixed> */
    public function sectionState(Section $section, int $term): array
    {
        $school = $section->getSchool();
        $schoolYear = $section->getSchoolYear();

        $teachers = [];
        foreach ($this->assignments->findTeacherAssignments($school, $schoolYear) as $assignment) {
            $teachers[] = ['id' => $assignment->getUser()->getId(), 'name' => $assignment->getUser()->getFullName()];
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
            $busy[(string) $entry->getTeacher()->getId()][$entry->getDay()->value.'-'.$entry->getPeriod()->getId()]
                = $entry->getLoad()->getSubject()->getName().', '.$entry->getSection()->getLabel();
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

    /** @return array<string, mixed> */
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
     * @return array{state: string, period: array{label: string, timeRange: string}|null, classes: list<array{teacherName: string, subjectName: string, sectionLabel: string}>, free: list<string>, next: array{label: string, startsAt: string}|null}
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
            } elseif (null === $next && !$period->isBreak() && $period->getStartsAt()->format('H:i:s') > $time) {
                $next = $period;
            }
        }
        $result['next'] = null === $next ? null : [
            'label' => 'Period '.$numbers[$next->getId()],
            'startsAt' => $next->getStartsAt()->format('H:i'),
        ];

        if (null === $current) {
            $result['state'] = $time < $periods[0]->getStartsAt()->format('H:i:s') ? 'before'
                : ($time >= end($periods)->getEndsAt()->format('H:i:s') ? 'after' : 'break');

            return $result;
        }

        $result['period'] = [
            'label' => $current->isBreak() ? (string) $current->getBreakLabel() : 'Period '.$numbers[$current->getId()],
            'timeRange' => $current->getStartsAt()->format('H:i').'–'.$current->getEndsAt()->format('H:i'),
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
        foreach ($this->assignments->findTeacherAssignments($school, $schoolYear) as $assignment) {
            if (!isset($busy[$assignment->getUser()->getId()])) {
                $result['free'][] = $assignment->getUser()->getFullName();
            }
        }
        $result['state'] = 'class';

        return $result;
    }

    /**
     * A teacher's own view of the moment: the class now and the next one today.
     *
     * @return array{state: string, current: array{subjectName: string, sectionLabel: string, endsAt: string}|null, next: array{subjectName: string, sectionLabel: string, startsAt: string}|null}
     */
    public function nowForTeacher(User $teacher, SchoolYear $schoolYear, \DateTimeImmutable $at): array
    {
        $result = ['state' => 'no_periods', 'current' => null, 'next' => null];
        $school = $this->assignments->findSchoolOf($teacher, $schoolYear, User::ROLE_TEACHER);
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
        usort($today, static fn (ScheduleEntry $a, ScheduleEntry $b) => $a->getPeriod()->getStartsAt() <=> $b->getPeriod()->getStartsAt());

        $current = null;
        $next = null;
        foreach ($today as $entry) {
            if ($this->contains($entry->getPeriod(), $time)) {
                $current = $entry;
            } elseif (null === $next && $entry->getPeriod()->getStartsAt()->format('H:i:s') > $time) {
                $next = $entry;
            }
        }
        $result['current'] = null === $current ? null : [
            'subjectName' => $current->getLoad()->getSubject()->getName(),
            'sectionLabel' => $current->getSection()->getLabel(),
            'endsAt' => $current->getPeriod()->getEndsAt()->format('H:i'),
        ];
        $result['next'] = null === $next ? null : [
            'subjectName' => $next->getLoad()->getSubject()->getName(),
            'sectionLabel' => $next->getSection()->getLabel(),
            'startsAt' => $next->getPeriod()->getStartsAt()->format('H:i'),
        ];

        if (null !== $current) {
            $result['state'] = 'class';
        } elseif (null === $next) {
            $result['state'] = 'done';
        } elseif ($time < $periods[0]->getStartsAt()->format('H:i:s')) {
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
        return $period->getStartsAt()->format('H:i:s') <= $time && $time < $period->getEndsAt()->format('H:i:s');
    }

    /** Semester 1 before the school year's midpoint, semester 2 from then on. */
    private function termAt(SchoolYear $schoolYear, \DateTimeImmutable $at): int
    {
        $start = $schoolYear->getStartDate()->getTimestamp();
        $midpoint = $start + intdiv($schoolYear->getEndDate()->getTimestamp() - $start, 2);

        return $at->getTimestamp() < $midpoint ? 1 : 2;
    }

    private function countsIn(ScheduleEntry $entry, int $term): bool
    {
        return 0 === $entry->getTerm() || $entry->getTerm() === $term;
    }

    /** @return list<array{value: int, label: string}> */
    private function days(): array
    {
        return array_map(static fn (Weekday $d) => ['value' => $d->value, 'label' => $d->short()], Weekday::cases());
    }

    /**
     * @param list<ClassPeriod> $periods in time order
     *
     * @return list<array<string, mixed>>
     */
    private function periodRows(array $periods): array
    {
        $numbers = $this->periods->numbering($periods);

        return array_map(static fn (ClassPeriod $p) => [
            'id' => $p->getId(),
            'label' => $p->isBreak() ? (string) $p->getBreakLabel() : 'Period '.$numbers[$p->getId()],
            'number' => $numbers[$p->getId()] ?? null,
            'isBreak' => $p->isBreak(),
            'startsAt' => $p->getStartsAt()?->format('H:i'),
            'endsAt' => $p->getEndsAt()?->format('H:i'),
        ], $periods);
    }
}
