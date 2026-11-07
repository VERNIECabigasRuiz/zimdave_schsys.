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

    /** Why nothing of the school can be changed now (inactive school, expired plan), or null. */
    public function blockedReason(School $school): ?string;
}
