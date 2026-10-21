<?php

namespace App\Service;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Repository\SchoolAssignmentRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * What a school's plan allows. Applies to everyone, administrators included.
 */
class PlanLimits
{
    public function __construct(
        private readonly SchoolAssignmentRepository $assignments,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Active teachers assigned to $school in $schoolYear, i.e. the seats in use. */
    public function countTeachers(School $school, SchoolYear $schoolYear): int
    {
        return $this->assignments->countTeachersBySchool($schoolYear, $school)[$school->getId()] ?? 0;
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

    /**
     * Why nothing at all can be added to the school (inactive or expired), or null.
     */
    public function blockedReason(School $school): ?string
    {
        if (!$school->isActive()) {
            return 'This school is inactive.';
        }
        if ($school->isPlanExpired($this->clock->now())) {
            return \sprintf(
                'The plan of %s expired on %s. Contact the administrator to renew.',
                $school->getName(),
                $school->getPlanValidUntil()->format('M j, Y'),
            );
        }

        return null;
    }
}
