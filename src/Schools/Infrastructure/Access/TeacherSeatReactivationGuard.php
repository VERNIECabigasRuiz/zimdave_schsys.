<?php

declare(strict_types=1);

namespace App\Schools\Infrastructure\Access;

use App\Access\Domain\Exception\AccountReactivationRefusedException;
use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Account\AccountReactivationGuardInterface;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Service\Plan\PlanLimits;

/**
 * Reactivating a teacher takes a seat of their school's plan this school year.
 */
class TeacherSeatReactivationGuard implements AccountReactivationGuardInterface
{
    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly PlanLimits $planLimits,
        private readonly TransactionLockInterface $locks,
    ) {
    }

    public function assertCanReactivate(User $account): void
    {
        $schoolYear = $this->schoolYears->findCurrent();
        if (User::ROLE_TEACHER !== $account->getRole() || null === $schoolYear) {
            return;
        }
        $school = $this->assignments->findSchoolOf($account, $schoolYear, User::ROLE_TEACHER);
        if (null === $school) {
            return;
        }

        // The same lock as every change to the school's teachers, so both never count the seats at once
        $this->locks->acquire(
            'school-teachers-' . $school->getId(),
            "Another change to this school's teachers is being saved. Please try again.",
        );
        if (null !== $refusal = $this->planLimits->canAddTeacher($school, $schoolYear)) {
            throw new AccountReactivationRefusedException($refusal);
        }
    }
}
