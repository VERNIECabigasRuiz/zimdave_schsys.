<?php

declare(strict_types=1);

namespace App\Schools\Domain\Service\Assignment;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\SchoolYear;

/**
 * What a teacher leaves behind when they leave a school. Implemented by the classes part of the app.
 */
interface TeacherDepartureInterface
{
    /** How many teaching loads $teacher has in $schoolYear. */
    public function countLoads(User $teacher, SchoolYear $schoolYear): int;

    /**
     * Stops $teacher advising their section in $schoolYear.
     *
     * @return string|null the section's label, or null when they advised none
     */
    public function clearAdvisory(User $teacher, SchoolYear $schoolYear): ?string;
}
