<?php

namespace App\Grading;

use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Repository\GradeLockRepository;
use App\Repository\GradeRepository;
use App\Repository\GradeUnlockRepository;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use App\Classes\Domain\Model\Repository\TeachingLoadRepositoryInterface;

/**
 * Every gradable class of a school for one period, with its teacher, progress and status. The school's
 * opening, lock and unlocks are read once and each section's grades once, so the page doesn't query per class.
 */
class SchoolGradeOverview
{
    public function __construct(
        private readonly SectionRepositoryInterface $sections,
        private readonly SubjectRepositoryInterface $subjects,
        private readonly TeachingLoadRepositoryInterface $loads,
        private readonly GradeRepository $grades,
        private readonly GradeLockRepository $locks,
        private readonly GradeUnlockRepository $unlocks,
        private readonly GradeRules $rules,
    ) {
    }

    /** @return list<ClassProgress> by grade level, section name, then the subject's place on the report card */
    public function build(School $school, SchoolYear $schoolYear, GradingPeriod $period): array
    {
        $locked = null !== $this->locks->findFor($school, $period);
        $open = !$locked && $this->rules->isOpenFor($school, $period);
        $unlocked = [];
        if ($locked) {
            foreach ($this->unlocks->findForSchool($school, $period) as $unlock) {
                $unlocked[$unlock->getSection()->getId().'-'.$unlock->getSubject()->getId()] = true;
            }
        }

        $classes = [];
        foreach ($this->sections->findForSchool($school, $schoolYear) as $section) {
            $subjects = array_values(array_filter(
                $this->subjects->findForSection($section),
                fn ($subject) => $this->rules->isGradableSubject($section, $subject),
            ));
            if ([] === $subjects) {
                continue;
            }

            $teachers = [];
            foreach ($this->loads->findForSection($section) as $load) {
                $teachers[$load->getSubject()->getId()] = $load->getTeacher()->getFullName();
            }
            $editable = array_values(array_filter($this->rules->sheet($section), static fn (SheetLearner $learner) => $learner->editable));
            $saved = $this->grades->findForEnrollmentsByPeriod(GradeRules::enrollmentIds($editable), $period);

            foreach ($subjects as $subject) {
                $graded = 0;
                foreach ($editable as $learner) {
                    if (isset($saved[$learner->enrollment->getId()][$subject->getId()])) {
                        ++$graded;
                    }
                }
                $classes[] = new ClassProgress(
                    $section,
                    $subject,
                    $teachers[$subject->getId()] ?? null,
                    $graded,
                    \count($editable),
                    GradeRules::statusFor($open, $locked, isset($unlocked[$section->getId().'-'.$subject->getId()])),
                );
            }
        }

        return $classes;
    }
}
