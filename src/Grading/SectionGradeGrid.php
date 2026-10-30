<?php

namespace App\Grading;

use App\Entity\GradingPeriod;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Repository\GradeRepository;
use App\Repository\SubjectRepository;
use App\Repository\TeachingLoadRepository;

/**
 * Every subject's grades of one section for one period: a column per gradable subject, and after
 * a parent's components a column for the parent's average. Learners who can't be edited are
 * listed with whatever grades they have but don't count toward a column's totals.
 */
class SectionGradeGrid
{
    public function __construct(
        private readonly SubjectRepository $subjects,
        private readonly TeachingLoadRepository $loads,
        private readonly GradeRepository $grades,
        private readonly GradeRules $rules,
    ) {
    }

    /** The period a grid opens on: the open one (the earliest if several), else the last. */
    public function defaultPeriod(SchoolYear $schoolYear): ?GradingPeriod
    {
        $periods = $schoolYear->getGradingPeriods()->getValues();
        foreach ($periods as $period) {
            if ($period->isOpen()) {
                return $period;
            }
        }

        return [] === $periods ? null : end($periods);
    }

    /**
     * @return array{columns: list<GridColumn>, rows: list<array{learner: SheetLearner, cells: array<string, ?int>}>}
     */
    public function build(Section $section, GradingPeriod $period): array
    {
        $subjects = $this->subjects->findForSection($section);
        $learners = $this->rules->sheet($section);
        // by enrollment: a learner who transferred out has moved section but keeps the grades given here
        $saved = $this->grades->findForEnrollmentsByPeriod(GradeRules::enrollmentIds($learners), $period);

        $teachers = [];
        foreach ($this->loads->findForSection($section) as $load) {
            $teachers[$load->getSubject()->getId()] = $load->getTeacher()->getFullName();
        }

        $componentsOf = [];
        foreach ($subjects as $subject) {
            if (null !== $subject->getParent()) {
                $componentsOf[$subject->getParent()->getId()][] = $subject;
            }
        }

        $rows = [];
        foreach ($learners as $learner) {
            $rows[] = ['learner' => $learner, 'cells' => []];
        }

        $columns = [];
        foreach ($subjects as $subject) {
            if (null !== $subject->getParent()) {
                continue;
            }
            $components = $componentsOf[$subject->getId()] ?? [];
            if ([] === $components) {
                $columns[] = $this->column($subject, 's'.$subject->getId(), $subject->getName(), $teachers[$subject->getId()] ?? null, false, $rows, $saved);
                continue;
            }
            foreach ($components as $component) {
                $columns[] = $this->column($component, 's'.$component->getId(), $component->getName(), $teachers[$component->getId()] ?? null, false, $rows, $saved);
            }
            $columns[] = $this->column($subject, 'avg'.$subject->getId(), $subject->getName(), null, true, $rows, $saved);
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Fills the column's cell in every row and counts it.
     *
     * @param list<array{learner: SheetLearner, cells: array<string, ?int>}> $rows
     * @param array<int, array<int, \App\Entity\Grade>>                      $saved
     */
    private function column(Subject $subject, string $key, string $label, ?string $teacher, bool $average, array &$rows, array $saved): GridColumn
    {
        $graded = 0;
        $listed = 0;
        foreach ($rows as $i => $row) {
            $enrollmentId = $row['learner']->enrollment->getId();
            $bySubject = $saved[$enrollmentId] ?? [];
            if ($average) {
                $values = array_map(static fn ($grade) => $grade->getValue(), $bySubject);
                $value = $this->rules->parentGrade($subject, $values);
            } else {
                $value = isset($bySubject[$subject->getId()]) ? $bySubject[$subject->getId()]->getValue() : null;
            }
            $rows[$i]['cells'][$key] = $value;
            if ($row['learner']->editable) {
                ++$listed;
                if (null !== $value) {
                    ++$graded;
                }
            }
        }

        return new GridColumn($key, $label, $teacher, $average, $graded, $listed);
    }
}
