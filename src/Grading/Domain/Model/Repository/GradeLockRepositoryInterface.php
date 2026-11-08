<?php

declare(strict_types=1);

namespace App\Grading\Domain\Model\Repository;

use App\Grading\Domain\Model\GradeLock;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;

interface GradeLockRepositoryInterface
{
    public function findFor(School $school, GradingPeriod $period): ?GradeLock;

    /** Locks of the period in any school. */
    public function countForPeriod(GradingPeriod $period): int;

    public function add(GradeLock $lock): void;

    public function remove(GradeLock $lock): void;
}
