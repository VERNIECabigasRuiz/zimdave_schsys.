<?php

declare(strict_types=1);

namespace App\Classes\Application\Service\Timetable;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Model\User;
use App\Classes\Application\Service\ClassesScope;
use App\Classes\Domain\Exception\ClassPeriodNotFoundException;
use App\Classes\Domain\Exception\ScheduleEntryNotFoundException;
use App\Classes\Domain\Exception\SubjectNotFoundException;
use App\Classes\Domain\Model\ClassPeriod;
use App\Classes\Domain\Model\Repository\ClassPeriodRepositoryInterface;
use App\Classes\Domain\Model\Repository\ScheduleEntryRepositoryInterface;
use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use App\Classes\Domain\Model\ScheduleEntry;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\TeacherNotFoundException;

/** Finds what a timetable request names, within the section's school and the current school year. */
class TimetableScope
{
    public function __construct(
        private readonly ClassesScope $scope,
        private readonly SubjectRepositoryInterface $subjects,
        private readonly ClassPeriodRepositoryInterface $periods,
        private readonly ScheduleEntryRepositoryInterface $entries,
        private readonly AccountDirectoryInterface $accounts,
        private readonly SchoolDirectoryInterface $schools,
    ) {
    }

    /** A section of the school in the current school year. */
    public function section(int $schoolId, int $sectionId): Section
    {
        $school = $this->scope->school($schoolId);

        return $this->scope->section($school, $this->scope->currentYear(), $sectionId);
    }

    public function subject(Section $section, int $subjectId): Subject
    {
        $subject = $this->subjects->findById($subjectId);
        if (
            null === $subject
            || $subject->getSchool() !== $section->getSchool()
            || $subject->getSchoolYear() !== $section->getSchoolYear()
        ) {
            throw new SubjectNotFoundException('This subject is not in the school this school year.');
        }

        return $subject;
    }

    public function period(Section $section, int $periodId): ClassPeriod
    {
        $period = $this->periods->findById($periodId);
        if (
            null === $period
            || $period->getSchool() !== $section->getSchool()
            || $period->getSchoolYear() !== $section->getSchoolYear()
        ) {
            throw new ClassPeriodNotFoundException('This class period is not in the school this school year.');
        }

        return $period;
    }

    /** A teacher of the section's school this school year. */
    public function teacher(Section $section, int $teacherId): User
    {
        $teacher = $this->accounts->findById($teacherId);
        if (
            null === $teacher
            || $section->getSchool() !== $this->schools->schoolOf(
                $teacher,
                $section->getSchoolYear(),
                User::ROLE_TEACHER,
            )
        ) {
            throw new TeacherNotFoundException('This teacher is not in the school this school year.');
        }

        return $teacher;
    }

    public function entry(Section $section, int $entryId): ScheduleEntry
    {
        $entry = $this->entries->findById($entryId);
        if (null === $entry || $entry->getSection() !== $section) {
            throw new ScheduleEntryNotFoundException();
        }

        return $entry;
    }
}
