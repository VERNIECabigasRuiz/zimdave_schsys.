<?php

declare(strict_types=1);

namespace App\Schools\Domain\Service\Plan;

use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/**
 * How many learners are enrolled, for the plan's student places. Implemented by the learners part of the app.
 */
interface EnrolledLearnersInterface
{
    public function countForSchool(School $school, SchoolYear $schoolYear): int;

    public function countForSection(int $sectionId): int;
}
