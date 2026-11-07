<?php

declare(strict_types=1);

namespace App\Schools\Domain\Model\Repository;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Schools\Domain\Model\SchoolYear;

interface SchoolAssignmentRepositoryInterface
{
    public function findPrincipal(School $school, SchoolYear $schoolYear): ?SchoolAssignment;

    /** The school where $user works as $role in $schoolYear. */
    public function findSchoolOf(User $user, SchoolYear $schoolYear, string $role): ?School;

    public function findForUser(User $user, SchoolYear $schoolYear): ?SchoolAssignment;

    /** @return array<int, SchoolAssignment> principal assignments keyed by school id */
    public function findPrincipalsBySchool(SchoolYear $schoolYear): array;

    /** A principal assignment of $user in a school year that has not ended yet. */
    public function findUnfinishedPrincipalAssignment(User $user): ?SchoolAssignment;

    /** @return array<int, string> school name keyed by user id */
    public function findSchoolNamesByUser(SchoolYear $schoolYear): array;

    /**
     * Active teachers per school in $schoolYear; these are the seats a plan limits.
     *
     * @return array<int, int> teacher count keyed by school id
     */
    public function countTeachersBySchool(SchoolYear $schoolYear, ?School $school = null): array;

    /**
     * Teachers of $school in $schoolYear (active or not), by name.
     *
     * @return list<SchoolAssignment>
     */
    public function findTeacherAssignments(School $school, SchoolYear $schoolYear): array;

    /**
     * Accounts assigned to $school as teachers in $schoolYear, by name.
     *
     * @return list<User>
     */
    public function findAssignedTeachers(School $school, SchoolYear $schoolYear): array;

    /**
     * Last year's teachers of $school who are not yet teachers of it in $current, by name.
     *
     * @return list<SchoolAssignment>
     */
    public function findTeachersToBringBack(School $school, SchoolYear $previous, SchoolYear $current): array;

    public function countPrincipals(SchoolYear $schoolYear): int;

    /**
     * Active principals with no school in $schoolYear, plus $include (the school's current principal).
     *
     * @return list<User>
     */
    public function findAssignablePrincipals(SchoolYear $schoolYear, ?User $include = null): array;

    /** Saved on the next flush. */
    public function add(SchoolAssignment $assignment): void;

    /** Removed on the next flush. */
    public function remove(SchoolAssignment $assignment): void;
}
