<?php

declare(strict_types=1);

namespace App\Schools\Application\Service;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/** The school a principal heads in the current school year. */
final class PrincipalSchoolFinder
{
    public function __construct(
        private readonly AccountDirectoryInterface $accounts,
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
    ) {
    }

    /** @return array{?User, ?SchoolYear, ?School} the account, the current year and the school */
    public function find(int $userId): array
    {
        $user = $this->accounts->findById($userId);
        $schoolYear = $this->schoolYears->findCurrent();
        $school = null !== $user && null !== $schoolYear
            ? $this->assignments->findSchoolOf($user, $schoolYear, User::ROLE_PRINCIPAL)
            : null;

        return [$user, $schoolYear, $school];
    }
}
