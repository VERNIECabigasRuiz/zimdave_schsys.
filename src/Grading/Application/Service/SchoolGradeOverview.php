<?php

declare(strict_types=1);

namespace App\Grading\Application\Service;

use App\Classes\Application\Api\SectionDirectoryInterface;
use App\Classes\Application\Api\TeachingLoadDirectoryInterface;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Grading\Domain\Model\Repository\GradeLockRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeUnlockRepositoryInterface;

/**
 * Every gradable class of a school for one period, with its teacher, progress and status. The school's
 * opening, lock and unlocks are read once and each section's grades once, so the page doesn't query per class.
 */
class SchoolGradeOverview
{
    public function __construct(
        private readonly SectionDirectoryInterface $sections,
        private readonly TeachingLoadDirectoryInterface $loads,
        private readonly GradeRepositoryInterface $grades,
        private readonly GradeLockRepositoryInterface $locks,
        private readonly GradeUnlockRepositoryInterface $unlocks,
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
                $unlocked[$unlock->getSection()->getId() . '-' . $unlock->getSubject()->getId()] = true;
            }
        }

        $classes = [];
        foreach ($this->sections->sectionsOf($school, $schoolYear) as $section) {
            $subjects = array_values(array_filter(
                $this->sections->subjectsOf($section),
                fn ($subject) => $this->rules->isGradableSubject($section, $subject),
            ));
            if ([] === $subjects) {
                continue;
            }

            $teachers = [];
            foreach ($this->loads->loadsOf($section) as $load) {
                $teachers[$load->getSubject()->getId()] = $load->getTeacher()->getFullName();
            }
            $editable = array_values(
                array_filter($this->rules->sheet($section), static fn (SheetLearner $learner) => $learner->editable),
            );
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
                    GradeRules::statusFor(
                        $open,
                        $locked,
                        isset($unlocked[$section->getId() . '-' . $subject->getId()]),
                    ),
                );
            }
        }

        return $classes;
    }
}
