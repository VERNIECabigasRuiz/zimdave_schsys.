<?php

declare(strict_types=1);

namespace App\Grading\Application\Service;

use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Grading\Domain\Model\ClassGradeStatus;
use App\Grading\Domain\Model\Repository\GradeLockRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeOpeningRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeUnlockRepositoryInterface;
use App\Learners\Application\Api\SectionRosterInterface;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\SexAtBirth;
use App\Learners\Domain\Model\Sf1Remark;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;

/** What may be graded, when grades may change, and who is on a grade sheet. Who may do it is GradePolicy's question. */
class GradeRules
{
    public function __construct(
        private readonly GradeRepositoryInterface $grades,
        private readonly GradeLockRepositoryInterface $locks,
        private readonly GradeUnlockRepositoryInterface $unlocks,
        private readonly SectionRosterInterface $roster,
        private readonly GradeOpeningRepositoryInterface $openings,
    ) {
    }

    public function isGradable(TeachingLoad $load): bool
    {
        return $this->isGradableSubject($load->getSection(), $load->getSubject());
    }

    /** A Grade 1–10 section of the current school year, and a subject without components. */
    public function isGradableSubject(Section $section, Subject $subject): bool
    {
        $level = $section->getGradeLevel();

        return $section->getSchoolYear()->isCurrent()
            && null !== $level
            && $level->value >= GradeLevel::Grade1->value
            && $level->value <= GradeLevel::Grade10->value
            && !$subject->hasComponents();
    }

    /** Whether the school has opened the period for grading. Each school opens and closes its own. */
    public function isOpenFor(School $school, GradingPeriod $period): bool
    {
        return null !== $this->openings->findFor($school, $period);
    }

    /**
     * A principal's lock wins over the period being open; a class unlocked for corrections stays so however
     * the period is set.
     */
    public function status(Section $section, Subject $subject, GradingPeriod $period): ClassGradeStatus
    {
        $school = $section->getSchool();
        if (null === $this->locks->findFor($school, $period)) {
            return self::statusFor($this->isOpenFor($school, $period), locked: false, unlocked: false);
        }

        // once locked, being open no longer matters
        return self::statusFor(
            false,
            locked: true,
            unlocked: null !== $this->unlocks->findFor($section, $subject, $period),
        );
    }

    /**
     * status() from what is already loaded: whether the school has the period open, has locked it, and has
     * unlocked the class.
     */
    public static function statusFor(bool $open, bool $locked, bool $unlocked): ClassGradeStatus
    {
        if ($locked) {
            return $unlocked ? ClassGradeStatus::Unlocked : ClassGradeStatus::Locked;
        }

        return $open ? ClassGradeStatus::Open : ClassGradeStatus::Closed;
    }

    /** Why grades with this status can't be changed, or null when they can. */
    public function refusalToEdit(ClassGradeStatus $status, GradingPeriod $period): ?string
    {
        return match ($status) {
            ClassGradeStatus::Locked => \sprintf('%s is locked by the principal.', $period->getLabel()),
            ClassGradeStatus::Closed => \sprintf("%s isn't open for grading.", $period->getLabel()),
            ClassGradeStatus::Open, ClassGradeStatus::Unlocked => null,
        };
    }

    /**
     * The section's class list, plus the learners who transferred out of it this school year.
     * Dropped and transferred-out learners are listed but can't be edited.
     *
     * @return list<SheetLearner> males first, then females, each by name
     */
    public function sheet(Section $section): array
    {
        $rows = [];
        foreach ($this->roster->classList($section) as $enrollment) {
            $dropped = Sf1Remark::Dropped === $enrollment->getRemarkCode();
            $rows[(int) $enrollment->getId()] = $this->row(
                $enrollment,
                editable: !$dropped,
                status: $dropped ? 'Dropped' : null,
            );
        }

        $schoolYear = $section->getSchoolYear();
        $since = $schoolYear->getStartDate()
            ?? new \DateTimeImmutable(substr($schoolYear->getName(), 0, 4) . '-06-01');
        foreach ($this->roster->transfersOutSince($section, $since) as $transfer) {
            $enrollment = $transfer->getEnrollment();
            // one that moved back is already on the class list; one that left twice is listed once
            if (null === $enrollment || isset($rows[(int) $enrollment->getId()])) {
                continue;
            }
            $rows[(int) $enrollment->getId()] = $this->row($enrollment, editable: false, status: 'Transferred out');
        }

        $rows = array_values($rows);
        usort(
            $rows,
            static fn (SheetLearner $a, SheetLearner $b)
                => [$b->male, mb_strtoupper($a->name)] <=> [$a->male, mb_strtoupper($b->name)],
        );

        return $rows;
    }

    /**
     * The enrollment ids of a sheet's rows, to look their grades up by.
     *
     * @param list<SheetLearner> $learners
     *
     * @return list<int>
     */
    public static function enrollmentIds(array $learners): array
    {
        return array_map(static fn (SheetLearner $learner) => (int) $learner->enrollment->getId(), $learners);
    }

    /**
     * A parent subject's grade: the rounded average of its components' grades, only when every component has one.
     *
     * @param array<int, ?int> $componentGrades by subject id
     */
    public function parentGrade(Subject $parent, array $componentGrades): ?int
    {
        $components = $parent->getComponents();
        if ($components->isEmpty()) {
            return null;
        }

        $sum = 0;
        foreach ($components as $component) {
            $grade = $componentGrades[$component->getId()] ?? null;
            if (null === $grade) {
                return null;
            }
            $sum += $grade;
        }

        return (int) round($sum / \count($components));
    }

    /** A period can be locked while the school has it open, or once the school has grades in it. */
    public function canLock(School $school, GradingPeriod $period): bool
    {
        return $this->isOpenFor($school, $period) || $this->grades->countForSchool($school, $period) > 0;
    }

    private function row(Enrollment $enrollment, bool $editable, ?string $status): SheetLearner
    {
        $learner = $enrollment->getLearner();
        $lrn = $learner->getLrn();

        return new SheetLearner(
            $enrollment,
            $learner->getSf1Name(),
            SexAtBirth::Male === $learner->getSex(),
            '' === $lrn ? null : $lrn,
            $editable,
            $status,
        );
    }
}
