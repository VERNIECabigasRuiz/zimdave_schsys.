<?php

declare(strict_types=1);

namespace App\Schools\Application\Api;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Domain\Service\Plan\PlanLimits;

final class SchoolDirectory implements SchoolDirectoryInterface
{
    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly PlanLimits $planLimits,
    ) {
    }

    public function findSchool(int $id): ?School
    {
        return $this->schools->findById($id);
    }

    public function currentYear(): ?SchoolYear
    {
        return $this->schoolYears->findCurrent();
    }

    public function previousYear(SchoolYear $schoolYear): ?SchoolYear
    {
        return $this->schoolYears->findPrevious($schoolYear);
    }

    public function schoolOf(User $user, SchoolYear $schoolYear, string $role): ?School
    {
        return $this->assignments->findSchoolOf($user, $schoolYear, $role);
    }

    public function teachersOf(School $school, SchoolYear $schoolYear): array
    {
        return $this->assignments->findAssignedTeachers($school, $schoolYear);
    }

    public function blockedReason(School $school): ?string
    {
        return $this->planLimits->blockedReason($school);
    }
}
