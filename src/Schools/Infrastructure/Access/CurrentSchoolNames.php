<?php

declare(strict_types=1);

namespace App\Schools\Infrastructure\Access;

use App\Access\Domain\Service\Account\AccountSchoolNamesInterface;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;

class CurrentSchoolNames implements AccountSchoolNamesInterface
{
    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
    ) {
    }

    public function schoolNamesByAccount(): array
    {
        $currentYear = $this->schoolYears->findCurrent();

        return null !== $currentYear ? $this->assignments->findSchoolNamesByUser($currentYear) : [];
    }
}
