<?php

declare(strict_types=1);

namespace App\Staff\Application\Service;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Model\User;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Staff\Domain\Exception\StaffNotFoundException;
use App\Staff\Domain\Model\PersonalDataSheet;
use App\Staff\Domain\Model\Repository\PersonalDataSheetRepositoryInterface;

/** Finds what a staff page names: the account, its Personal Data Sheet, and a school's teacher this school year. */
class StaffScope
{
    public function __construct(
        private readonly AccountDirectoryInterface $accounts,
        private readonly SchoolDirectoryInterface $schools,
        private readonly PersonalDataSheetRepositoryInterface $sheets,
    ) {
    }

    /** @throws AccountNotFoundException */
    public function account(int $accountId): User
    {
        return $this->accounts->findById($accountId) ?? throw new AccountNotFoundException();
    }

    /** The person's sheet, started (not saved yet) when they have none. */
    public function sheetOf(User $user): PersonalDataSheet
    {
        return $this->sheets->findFor($user) ?? new PersonalDataSheet($user);
    }

    /**
     * A teacher of the school this school year whose account is still a teacher.
     *
     * @throws StaffNotFoundException
     */
    public function schoolTeacher(int $schoolId, int $teacherId): User
    {
        $school = $this->schools->findSchool($schoolId) ?? throw new StaffNotFoundException();
        $schoolYear = $this->schools->currentYear() ?? throw new StaffNotFoundException('No current school year.');
        foreach ($this->schools->currentTeachersOf($school, $schoolYear) as $teacher) {
            if ($teacher->getId() === $teacherId) {
                return $teacher;
            }
        }

        throw new StaffNotFoundException('This teacher does not belong to the school this school year.');
    }
}
