<?php

declare(strict_types=1);

namespace App\Schools\Application\Api;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\Division;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Schools\Domain\Model\SchoolYear;

/**
 * The schools, school years and staff other modules may read.
 */
interface SchoolDirectoryInterface
{
    /** The security attribute that lets a user manage a school's pages. */
    public const MANAGE = 'SCHOOL_MANAGE';

    public function findSchool(int $id): ?School;

    public function currentYear(): ?SchoolYear;

    /** The school year that started right before $schoolYear. */
    public function previousYear(SchoolYear $schoolYear): ?SchoolYear;

    /** The school $user works at in $schoolYear with $role, if any. */
    public function schoolOf(User $user, SchoolYear $schoolYear, string $role): ?School;

    /** The account heading $school in $schoolYear, if any. */
    public function principalOf(School $school, SchoolYear $schoolYear): ?User;

    /** Where $user works in $schoolYear and in which role, if anywhere. */
    public function assignmentOf(User $user, SchoolYear $schoolYear): ?SchoolAssignment;

    /** The Divisions entry of $school's region and division, if the administrator added one. */
    public function divisionOf(School $school): ?Division;

    /** @return list<\DateTimeImmutable> the school days from $from to $to, both included */
    public function schoolDays(
        School $school,
        SchoolYear $schoolYear,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array;

    /** @return list<\DateTimeImmutable> the school days of the calendar month $month falls in */
    public function monthDays(School $school, SchoolYear $schoolYear, \DateTimeImmutable $month): array;

    /** The school's day off on $date, if it is one. */
    public function dayOff(School $school, \DateTimeImmutable $date): ?SchoolDayOff;

    /** @return array<string, SchoolDayOff> the school's days off from $from to $to, keyed by Y-m-d */
    public function daysOffBetween(School $school, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    public function isSchoolDay(School $school, SchoolYear $schoolYear, \DateTimeImmutable $date): bool;

    /** The enrolment cut-off: the first Friday of the school calendar ("1st Friday of June"). */
    public function cutOff(SchoolYear $schoolYear): \DateTimeImmutable;

    /**
     * Accounts assigned to $school as teachers in $schoolYear, by name.
     *
     * @return list<User>
     */
    public function teachersOf(School $school, SchoolYear $schoolYear): array;

    /**
     * Like teachersOf(), without accounts that are no longer teachers (promoted since).
     *
     * @return list<User>
     */
    public function currentTeachersOf(School $school, SchoolYear $schoolYear): array;

    /** Why nothing of the school can be changed now (inactive school, expired plan), or null. */
    public function blockedReason(School $school): ?string;

    /** Why the school may not enroll another learner in the section (plan, seats, section full), or null. */
    public function studentRefusal(
        School $school,
        SchoolYear $schoolYear,
        int $sectionId,
        string $sectionLabel,
    ): ?string;

    /** Why the section takes no more learners (only on a plan without a principal), or null. */
    public function sectionFullReason(School $school, int $sectionId, string $sectionLabel): ?string;

    /**
     * Every school, in name order.
     *
     * @return list<School>
     */
    public function allSchools(): array;
}
