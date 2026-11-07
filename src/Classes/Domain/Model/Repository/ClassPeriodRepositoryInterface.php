<?php

declare(strict_types=1);

namespace App\Classes\Domain\Model\Repository;

use App\Classes\Domain\Model\ClassPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

interface ClassPeriodRepositoryInterface
{
    public function findById(int $id): ?ClassPeriod;

    /** @return list<ClassPeriod> in time order, breaks included */
    public function findForSchool(School $school, SchoolYear $schoolYear): array;

    /**
     * @param list<ClassPeriod> $periods in time order
     *
     * @return array<int, int> period id => its number among the class periods (breaks have none)
     */
    public function numbering(array $periods): array;

    /** "Period 3", or the break label. */
    public function label(ClassPeriod $period): string;

    /** Saved on the next flush. */
    public function add(ClassPeriod $period): void;

    /** Removed on the next flush. */
    public function remove(ClassPeriod $period): void;
}
