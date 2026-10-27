<?php

namespace App\Service;

use App\Entity\ClassPeriod;
use App\Entity\ScheduleEntry;
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
