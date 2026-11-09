<?php

declare(strict_types=1);

namespace App\Schools\Domain\Service\Plan;

use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Schools\Domain\Exception\PlanLimitException;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/**
 * What a school's plan allows. Applies to everyone, administrators included.
 */
class PlanLimits
{
    public function __construct(
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly EnrolledLearnersInterface $learners,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** Active teachers assigned to $school in $schoolYear, i.e. the seats in use. */
    public function countTeachers(School $school, SchoolYear $schoolYear): int
    {
        return $this->assignments->countTeachersBySchool($schoolYear, $school)[(int) $school->getId()] ?? 0;
    }

    /**
     * @throws PlanLimitException when the school may not get another teacher
     */
    public function assertCanAddTeacher(School $school, SchoolYear $schoolYear): void
    {
        if (null !== $reason = $this->canAddTeacher($school, $schoolYear)) {
            throw new PlanLimitException($reason);
        }
    }

    /**
     * @return string|null why another teacher is refused, or null when it is allowed
     */
    public function canAddTeacher(School $school, SchoolYear $schoolYear): ?string
    {
        if (null !== $reason = $this->blockedReason($school)) {
            return $reason;
        }

        $used = $this->countTeachers($school, $schoolYear);
        $limit = $school->getTeacherLimit();
        if ($used >= $limit) {
            return \sprintf(
                'The %s plan allows %d teacher(s) (%d used). Contact the administrator to upgrade.',
                $school->getPlan()?->getName(),
                $limit,
                $used,
            );
        }

        return null;
    }

    /** Learners enrolled in $school in $schoolYear, i.e. the student places in use. */
    public function countStudents(School $school, SchoolYear $schoolYear): int
    {
        return $this->learners->countForSchool($school, $schoolYear);
    }

    /**
     * @throws PlanLimitException when the school may not enroll another learner in the section
     */
    public function assertCanAddStudent(
        School $school,
        SchoolYear $schoolYear,
        int $sectionId,
        string $sectionLabel,
    ): void {
        if (null !== $reason = $this->canAddStudent($school, $schoolYear, $sectionId, $sectionLabel)) {
            throw new PlanLimitException($reason);
        }
    }

    /**
     * @return string|null why another learner is refused, or null when it is allowed
     */
    public function canAddStudent(School $school, SchoolYear $schoolYear, int $sectionId, string $sectionLabel): ?string
    {
        if (null !== $reason = $this->blockedReason($school)) {
            return $reason;
        }

        $used = $this->countStudents($school, $schoolYear);
        $limit = $school->getStudentLimit();
        if ($used >= $limit) {
            return \sprintf(
                'The %s plan allows %d student(s) (%d used). Contact the administrator to upgrade.',
                $school->getPlan()?->getName(),
                $limit,
                $used,
            );
        }

        return $this->sectionFullReason($school, $sectionId, $sectionLabel);
    }

    /**
     * Without a principal the teacher runs the class: a section holds at most one seat's worth.
     *
     * @return string|null why the section takes no more learners, or null
     */
    public function sectionFullReason(School $school, int $sectionId, string $sectionLabel): ?string
    {
        $plan = $school->getPlan();
        if (null !== $plan && !$plan->includesPrincipal()) {
            $perSection = $plan->getStudentsPerSeat();
            if ($this->learners->countForSection($sectionId) >= $perSection) {
                return \sprintf(
                    '%s is full (%d %s).',
                    $sectionLabel,
                    $perSection,
                    1 === $perSection ? 'learner' : 'learners',
                );
            }
        }

        return null;
    }

    /**
     * Why nothing at all can be added to the school (inactive or expired), or null.
     */
    public function blockedReason(School $school): ?string
    {
        if (!$school->isActive()) {
            return 'This school is inactive.';
        }
        $validUntil = $school->getPlanValidUntil();
        if (null !== $validUntil && $school->isPlanExpired($this->clock->now())) {
            return \sprintf(
                'The plan of %s expired on %s. Contact the administrator to renew.',
                $school->getName(),
                $validUntil->format('M j, Y'),
            );
        }

        return null;
    }
}
