<?php

declare(strict_types=1);

namespace App\Schools\Application\Service;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Model\User;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Exception\TeacherNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/** What every change to a school's teachers starts from: the school, this school year, the teacher, the lock. */
final class SchoolTeacherScope
{
    public const BUSY = "Another change to this school's teachers is being saved. Please try again.";

    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolTeacherManager $teachers,
        private readonly TransactionLockInterface $locks,
        private readonly AccountDirectoryInterface $accounts,
    ) {
    }

    /** A teacher account, for the admin's school screens of an account. */
    public function teacherAccount(int $accountId): User
    {
        $account = $this->accounts->findById($accountId);
        if (null === $account || User::ROLE_TEACHER !== $account->getRole()) {
            throw new TeacherNotFoundException('Only teacher accounts are assigned to a school here.');
        }

        return $account;
    }

    public function school(int $schoolId): School
    {
        return $this->schools->findById($schoolId) ?? throw new SchoolNotFoundException();
    }

    public function currentYear(): SchoolYear
    {
        return $this->schoolYears->findCurrent() ?? throw new SchoolYearNotFoundException('No current school year.');
    }

    public function teacher(School $school, SchoolYear $schoolYear, int $teacherId): User
    {
        return $this->teachers->findTeacher($school, $schoolYear, $teacherId) ?? throw new TeacherNotFoundException();
    }

    /**
     * Runs one change to the school's teachers at a time, so two can't both pass the seat check.
     * Call it before any read, or the transaction keeps counting from an older snapshot.
     */
    public function lock(int $schoolId): void
    {
        $this->locks->acquire('school-teachers-' . $schoolId, self::BUSY);
    }
}
