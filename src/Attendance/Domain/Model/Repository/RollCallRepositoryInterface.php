<?php

declare(strict_types=1);

namespace App\Attendance\Domain\Model\Repository;

use App\Attendance\Domain\Model\RollCall;
use App\Classes\Domain\Model\Section;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

interface RollCallRepositoryInterface
{
    public function findOne(Section $section, \DateTimeImmutable $date): ?RollCall;

    /** The day's roll call as it is saved now, even when an older copy was loaded earlier in the same request. */
    public function findFresh(Section $section, \DateTimeImmutable $date): ?RollCall;

    /** @return array<string, RollCall> keyed by Y-m-d, with their marks */
    public function findBetween(Section $section, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /** @return array<int, RollCall> the school's roll calls of the day, keyed by section id */
    public function findForSchoolOn(School $school, SchoolYear $schoolYear, \DateTimeImmutable $date): array;

    /**
     * How many of the school's sections took attendance on any of $dates.
     *
     * @param list<\DateTimeImmutable> $dates
     */
    public function countSectionsOn(School $school, array $dates): int;

    public function add(RollCall $rollCall): void;
}
