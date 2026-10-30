<?php

namespace App\Grading;

use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Repository\GradeLockRepository;
use App\Repository\GradeRepository;
use App\Repository\GradeUnlockRepository;
use App\Repository\SectionRepository;
use App\Repository\SubjectRepository;
use App\Repository\TeachingLoadRepository;

/**
 * Every gradable class of a school for one period, with its teacher, progress and status. The school's
 * lock and unlocks are read once and each section's grades once, so the page doesn't query per class.
 */
class SchoolGradeOverview
{
    public function __construct(
        private readonly SectionRepository $sections,
        private readonly SubjectRepository $subjects,
        private readonly TeachingLoadRepository $loads,
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
                    GradeRules::statusFor($period, $locked, isset($unlocked[$section->getId().'-'.$subject->getId()])),
                );
            }
        }

        return $classes;
    }
}
