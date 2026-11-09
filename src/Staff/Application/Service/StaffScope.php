<?php

declare(strict_types=1);

namespace App\Staff\Application\Service;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Model\User;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Staff\Domain\Exception\StaffAccessDeniedException;
use App\Staff\Domain\Exception\StaffNotFoundException;
use App\Staff\Domain\Model\PersonalDataSheet;
use App\Staff\Domain\Model\Repository\PersonalDataSheetRepositoryInterface;
use App\Staff\Domain\Model\Repository\ServiceRecordEntryRepositoryInterface;
use App\Staff\Domain\Model\ServiceRecordEntry;

/**
 * Finds what a staff page names: the account, its Personal Data Sheet, a school's teacher or employee this school
 * year, a row of a service record, and the school of someone who has an ID.
 */
class StaffScope
{
    public function __construct(
        private readonly AccountDirectoryInterface $accounts,
        private readonly SchoolDirectoryInterface $schools,
        private readonly PersonalDataSheetRepositoryInterface $sheets,
        private readonly ServiceRecordEntryRepositoryInterface $entries,
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
        $school = $this->school($schoolId);
        $schoolYear = $this->schools->currentYear() ?? throw new StaffNotFoundException('No current school year.');
        foreach ($this->schools->currentTeachersOf($school, $schoolYear) as $teacher) {
            if ($teacher->getId() === $teacherId) {
                return $teacher;
            }
        }

        throw new StaffNotFoundException('This teacher does not belong to the school this school year.');
    }

    /** @throws StaffNotFoundException */
    public function school(int $schoolId): School
    {
        return $this->schools->findSchool($schoolId) ?? throw new StaffNotFoundException();
    }

    /**
     * Someone assigned to the school this school year, in any role.
     *
     * @throws StaffNotFoundException
     */
    public function schoolEmployee(School $school, int $employeeId): User
    {
        $schoolYear = $this->schools->currentYear() ?? throw new StaffNotFoundException('No current school year.');
        $employee = $this->accounts->findById($employeeId);
        if (
            null === $employee
            || $this->schools->assignmentOf($employee, $schoolYear)?->getSchool()->getId() !== $school->getId()
        ) {
            throw new StaffNotFoundException('This person does not belong to the school this school year.');
        }

        return $employee;
    }

    /**
     * A row of the employee's service record.
     *
     * @throws StaffNotFoundException
     */
    public function serviceRow(User $employee, int $entryId): ServiceRecordEntry
    {
        $entry = $this->entries->findById($entryId);
        if (null === $entry || $entry->getEmployee()->getId() !== $employee->getId()) {
            throw new StaffNotFoundException('No such row in this service record.');
        }

        return $entry;
    }

    /**
     * The person's assignment this school year, as a teacher or principal: only they have an ID here.
     *
     * @throws StaffAccessDeniedException
     */
    public function idHolder(User $user): SchoolAssignment
    {
        $schoolYear = $this->schools->currentYear();
        $assignment = null === $schoolYear ? null : $this->schools->assignmentOf($user, $schoolYear);
        $role = $assignment?->getRole();
        if (null === $assignment || !\in_array($role, [User::ROLE_TEACHER, User::ROLE_PRINCIPAL], true)) {
            throw new StaffAccessDeniedException(
                'Only teachers and principals with a school this school year have an ID here.',
            );
        }

        return $assignment;
    }
}
