<?php

namespace App\Service\AccessHooks;

use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Account\AccountRoleChangeGuardInterface;
use App\Repository\SchoolAssignmentRepository;

/**
 * A principal still heading a school this (or a coming) school year must be removed there first.
 */
class PrincipalRoleChangeGuard implements AccountRoleChangeGuardInterface
{
    public function __construct(private readonly SchoolAssignmentRepository $assignments)
    {
    }

    public function refusal(User $account, ?string $originalRole): ?string
    {
        if (User::ROLE_PRINCIPAL !== $originalRole || User::ROLE_PRINCIPAL === $account->getRole()) {
            return null;
        }

        $assignment = $this->assignments->findUnfinishedPrincipalAssignment($account);
        if (null === $assignment) {
            return null;
        }

        return \sprintf(
            'Remove %s as principal of %s (%s) before changing the role.',
            $account->getFullName(),
            $assignment->getSchool()->getName(),
            $assignment->getSchoolYear()->getName(),
        );
    }
}
