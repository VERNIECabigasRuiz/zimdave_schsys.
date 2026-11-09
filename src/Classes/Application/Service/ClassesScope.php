<?php

declare(strict_types=1);

namespace App\Classes\Application\Service;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Model\User;
use App\Classes\Domain\Exception\ClassPeriodNotFoundException;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Classes\Domain\Model\ClassPeriod;
use App\Classes\Domain\Model\Repository\ClassPeriodRepositoryInterface;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Classes\Domain\Model\Section;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Exception\TeacherNotFoundException;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/** Finds what the class pages work on, and refuses what they may not. */
class ClassesScope
{
    public function __construct(
        private readonly SchoolDirectoryInterface $schools,
        private readonly AccountDirectoryInterface $accounts,
        private readonly SectionRepositoryInterface $sections,
        private readonly ClassPeriodRepositoryInterface $periods,
    ) {
    }

    public function school(int $schoolId): School
    {
        return $this->schools->findSchool($schoolId) ?? throw new SchoolNotFoundException();
    }

    public function currentYear(): SchoolYear
    {
        return $this->schools->currentYear() ?? throw new SchoolYearNotFoundException('No current school year.');
    }

    public function previousYear(SchoolYear $schoolYear): SchoolYear
    {
        return $this->schools->previousYear($schoolYear)
            ?? throw new SchoolYearNotFoundException('There is no previous school year.');
    }

    /** A section of $school in $schoolYear. */
    public function section(School $school, SchoolYear $schoolYear, int $sectionId): Section
    {
        $section = $this->sections->findById($sectionId);
        if (null === $section || $section->getSchool() !== $school || $section->getSchoolYear() !== $schoolYear) {
            throw new SectionNotFoundException();
        }

        return $section;
    }

    /** A period of $school's day in $schoolYear. */
    public function period(School $school, SchoolYear $schoolYear, int $periodId): ClassPeriod
    {
        $period = $this->periods->findById($periodId);
        if (null === $period || $period->getSchool() !== $school || $period->getSchoolYear() !== $schoolYear) {
            throw new ClassPeriodNotFoundException();
        }

        return $period;
    }

    public function account(int $accountId): User
    {
        return $this->accounts->findById($accountId) ?? throw new TeacherNotFoundException();
    }

    /** The school the teacher teaches at in $schoolYear, if any. */
    public function teacherSchool(User $teacher, SchoolYear $schoolYear): ?School
    {
        return $this->schools->schoolOf($teacher, $schoolYear, User::ROLE_TEACHER);
    }

    /** Teachers set up their own section and subjects only on a plan without a principal. */
    public static function teachersOwnClasses(School $school): bool
    {
        return null !== $school->getPlan() && !$school->getPlan()->includesPrincipal();
    }

    public function blockedReason(School $school): ?string
    {
        return $this->schools->blockedReason($school);
    }

    /**
     * Teachers the school may pick as advisers.
     *
     * @return list<array{id: int, fullName: string}>
     */
    public function teacherChoices(School $school, SchoolYear $schoolYear): array
    {
        return array_map(
            static fn (User $teacher) => ['id' => (int) $teacher->getId(), 'fullName' => $teacher->getFullName()],
            $this->schools->teachersOf($school, $schoolYear),
        );
    }
}
