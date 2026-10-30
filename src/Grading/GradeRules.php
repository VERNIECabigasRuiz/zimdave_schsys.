<?php

namespace App\Grading;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Sf1Remark;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Repository\EnrollmentRepository;
use App\Repository\GradeLockRepository;
use App\Repository\GradeRepository;
use App\Repository\GradeUnlockRepository;
use App\Repository\TransferRequestRepository;

/** What may be graded, when grades may change, and who is on a grade sheet. Who may do it is a voter's question. */
class GradeRules
{
    public function __construct(
        private readonly GradeRepository $grades,
        private readonly GradeLockRepository $locks,
        private readonly GradeUnlockRepository $unlocks,
        private readonly EnrollmentRepository $enrollments,
        private readonly TransferRequestRepository $transfers,
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

    /** A principal's lock wins over the period being open; a class unlocked for corrections stays so however the period is set. */
    public function status(Section $section, Subject $subject, GradingPeriod $period): ClassGradeStatus
    {
        if (null === $this->locks->findFor($section->getSchool(), $period)) {
            return self::statusFor($period, locked: false, unlocked: false);
        }

        return self::statusFor($period, locked: true, unlocked: null !== $this->unlocks->findFor($section, $subject, $period));
    }

    /** status() from a lock and an unlock already loaded: whether the school has locked the period, and whether the class is unlocked. */
    public static function statusFor(GradingPeriod $period, bool $locked, bool $unlocked): ClassGradeStatus
    {
        if ($locked) {
            return $unlocked ? ClassGradeStatus::Unlocked : ClassGradeStatus::Locked;
        }

        return $period->isOpen() ? ClassGradeStatus::Open : ClassGradeStatus::Closed;
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
        foreach ($this->enrollments->findClassList($section) as $enrollment) {
            $dropped = Sf1Remark::Dropped === $enrollment->getRemarkCode();
            $rows[$enrollment->getId()] = $this->row($enrollment, editable: !$dropped, status: $dropped ? 'Dropped' : null);
        }

        $schoolYear = $section->getSchoolYear();
        $since = $schoolYear->getStartDate() ?? new \DateTimeImmutable(substr($schoolYear->getName(), 0, 4).'-06-01');
        foreach ($this->transfers->findApprovedOutOf($section, $since) as $transfer) {
            $enrollment = $transfer->getEnrollment();
            // one that moved back is already on the class list; one that left twice is listed once
            if (null === $enrollment || isset($rows[$enrollment->getId()])) {
                continue;
            }
            $rows[$enrollment->getId()] = $this->row($enrollment, editable: false, status: 'Transferred out');
        }

        $rows = array_values($rows);
        usort($rows, static fn (SheetLearner $a, SheetLearner $b) => [$b->male, mb_strtoupper($a->name)] <=> [$a->male, mb_strtoupper($b->name)]);

        return $rows;
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

    /** A period can be locked while it is open, or once it has grades. */
    public function canLock(School $school, GradingPeriod $period): bool
    {
        return $period->isOpen() || $this->grades->countForSchool($school, $period) > 0;
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
