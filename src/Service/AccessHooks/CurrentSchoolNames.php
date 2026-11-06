<?php

namespace App\Service\AccessHooks;

use App\Access\Domain\Service\Account\AccountSchoolNamesInterface;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;

class CurrentSchoolNames implements AccountSchoolNamesInterface
{
    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
    ) {
    }

    public function schoolNamesByAccount(): array
    {
        $currentYear = $this->schoolYears->findCurrent();

        return null !== $currentYear ? $this->assignments->findSchoolNamesByUser($currentYear) : [];
    }
}
