<?php

declare(strict_types=1);

namespace App\Schools\Application\Api;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolDayOffRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Domain\Service\Calendar\SchoolCalendar;
use App\Schools\Domain\Service\Plan\PlanLimits;

final class SchoolDirectory implements SchoolDirectoryInterface
{
    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly PlanLimits $planLimits,
        private readonly SchoolCalendar $calendar,
        private readonly SchoolDayOffRepositoryInterface $daysOff,
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

    public function principalOf(School $school, SchoolYear $schoolYear): ?User
    {
        return $this->assignments->findPrincipal($school, $schoolYear)?->getUser();
    }

    public function schoolDays(
        School $school,
        SchoolYear $schoolYear,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->calendar->schoolDays($school, $schoolYear, $from, $to);
    }

    public function monthDays(School $school, SchoolYear $schoolYear, \DateTimeImmutable $month): array
    {
        return $this->calendar->monthDays($school, $schoolYear, $month);
    }

    public function dayOff(School $school, \DateTimeImmutable $date): ?SchoolDayOff
    {
        return $this->calendar->dayOff($school, $date);
    }

    public function daysOffBetween(School $school, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->daysOff->findBetween($school, $from, $to);
    }

    public function isSchoolDay(School $school, SchoolYear $schoolYear, \DateTimeImmutable $date): bool
    {
        return $this->calendar->isSchoolDay($school, $schoolYear, $date);
    }

    public function cutOff(SchoolYear $schoolYear): \DateTimeImmutable
    {
        return $this->calendar->cutOff($schoolYear);
    }

    public function teachersOf(School $school, SchoolYear $schoolYear): array
    {
        return $this->assignments->findAssignedTeachers($school, $schoolYear);
    }

    public function currentTeachersOf(School $school, SchoolYear $schoolYear): array
    {
        return array_map(
            static fn (SchoolAssignment $assignment): User => $assignment->getUser(),
            $this->assignments->findTeacherAssignments($school, $schoolYear),
        );
    }

    public function blockedReason(School $school): ?string
    {
        return $this->planLimits->blockedReason($school);
    }

    public function studentRefusal(
        School $school,
        SchoolYear $schoolYear,
        int $sectionId,
        string $sectionLabel,
    ): ?string {
        return $this->planLimits->canAddStudent($school, $schoolYear, $sectionId, $sectionLabel);
    }

    public function sectionFullReason(School $school, int $sectionId, string $sectionLabel): ?string
    {
        return $this->planLimits->sectionFullReason($school, $sectionId, $sectionLabel);
    }

    public function allSchools(): array
    {
        return $this->schools->findAllOrdered();
    }
}
