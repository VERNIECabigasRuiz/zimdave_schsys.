<?php

declare(strict_types=1);

namespace App\Schools\Domain\Service\SchoolYear;

use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\SchoolYear;

/**
 * What grading has done with a school year's periods. Implemented by the grading part of the app.
 */
interface GradingPeriodUsageInterface
{
    /** How many schools have $period open for grading. */
    public function countSchoolsOpen(GradingPeriod $period): int;

    /** Whether any school has a period of $schoolYear open for grading. */
    public function hasOpenPeriod(SchoolYear $schoolYear): bool;

    /** Whether $period has grades entered or locked in any school. */
    public function isGraded(GradingPeriod $period): bool;
}
