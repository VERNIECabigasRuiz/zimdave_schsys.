<?php

declare(strict_types=1);

namespace App\Dashboards\Infrastructure\Core;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Model\User;
use App\Classes\Application\Api\SectionDirectoryInterface;
use App\Core\Domain\Service\Menu\RoleMenuInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** Answers the role layouts' menu questions from the schools and sections of this school year. */
final class RoleMenu implements RoleMenuInterface
{
    public function __construct(
        private readonly AccountDirectoryInterface $accounts,
        private readonly SchoolDirectoryInterface $schools,
        private readonly SectionDirectoryInterface $sections,
    ) {
    }

    public function hasTeachingLoad(int $accountId): bool
    {
        $teacher = $this->accounts->findById($accountId);
        $schoolYear = $this->schools->currentYear();
        $school = null === $teacher || null === $schoolYear
            ? null
            : $this->schools->schoolOf($teacher, $schoolYear, User::ROLE_TEACHER);

        return false !== $school?->getPlan()?->includesPrincipal();
    }

    public function hasId(int $accountId): bool
    {
        $account = $this->accounts->findById($accountId);
        $schoolYear = $this->schools->currentYear();
        $role = null === $account || null === $schoolYear
            ? null
            : $this->schools->assignmentOf($account, $schoolYear)?->getRole();

        return \in_array($role, [User::ROLE_TEACHER, User::ROLE_PRINCIPAL], true);
    }

    public function advisesSection(int $accountId): bool
    {
        $teacher = $this->accounts->findById($accountId);
        $schoolYear = $this->schools->currentYear();

        return null !== $teacher && null !== $schoolYear && null !== $this->sections->advisoryOf($teacher, $schoolYear);
    }
}
