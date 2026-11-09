<?php

declare(strict_types=1);

namespace App\Dashboards\Application\Service;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Model\User;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/** The signed-in account, the current school year and the account's school in it. */
class DashboardScope
{
    public function __construct(
        private readonly AccountDirectoryInterface $accounts,
        private readonly SchoolDirectoryInterface $schools,
    ) {
    }

    public function account(int $accountId): User
    {
        return $this->accounts->findById($accountId)
            ?? throw new \LogicException('The signed-in account was not found.');
    }

    public function currentYear(): ?SchoolYear
    {
        return $this->schools->currentYear();
    }

    /** The school the account belongs to in $role this school year, if any. */
    public function schoolOf(User $account, ?SchoolYear $schoolYear, string $role): ?School
    {
        return null === $schoolYear ? null : $this->schools->schoolOf($account, $schoolYear, $role);
    }
}
