<?php

declare(strict_types=1);

namespace App\Schools\Domain\Model\Repository;

use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Schools\Domain\Model\SchoolYear;

interface SchoolDayOffRepositoryInterface
{
    public function findById(int $id): ?SchoolDayOff;

    /** @return array<string, SchoolDayOff> keyed by Y-m-d */
    public function findBetween(School $school, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /** @return list<SchoolDayOff> by date */
    public function findForYear(School $school, SchoolYear $schoolYear): array;

    /** Saved on the next flush. */
    public function add(SchoolDayOff $dayOff): void;

    /** Removed on the next flush. */
    public function remove(SchoolDayOff $dayOff): void;
}
