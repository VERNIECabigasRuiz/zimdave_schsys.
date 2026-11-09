<?php

declare(strict_types=1);

namespace App\Grading\Application\Api;

use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/**
 * The grading figures other modules may read.
 */
interface GradingDirectoryInterface
{
    /** The grading period the school's grade pages open on, or null when the year has none. */
    public function defaultPeriod(School $school, SchoolYear $schoolYear): ?GradingPeriod;

    /** How many of the school's learners fail at least one subject in $period. */
    public function failingCount(School $school, SchoolYear $schoolYear, GradingPeriod $period): int;

    /** How many grade change requests of the school wait for the principal. */
    public function pendingChangeRequests(School $school, SchoolYear $schoolYear): int;
}
