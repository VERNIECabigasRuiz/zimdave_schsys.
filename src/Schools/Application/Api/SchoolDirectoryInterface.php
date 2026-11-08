<?php

declare(strict_types=1);

namespace App\Schools\Application\Api;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\School;
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
