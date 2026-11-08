<?php

declare(strict_types=1);

namespace App\Grading\Domain\Model\Repository;

use App\Grading\Domain\Model\GradeOpening;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

interface GradeOpeningRepositoryInterface
{
    public function findFor(School $school, GradingPeriod $period): ?GradeOpening;

    /** @return list<int> ids of the school's open grading periods in the school year */
    public function findOpenPeriodIds(School $school, SchoolYear $schoolYear): array;

    /** Whether any school has opened any period of the school year. */
    public function existsForSchoolYear(SchoolYear $schoolYear): bool;

    public function countSchoolsOpen(GradingPeriod $period): int;

    public function add(GradeOpening $opening): void;

    public function remove(GradeOpening $opening): void;
}
