<?php

declare(strict_types=1);

namespace App\Attendance\Application\Api;

use App\Attendance\Application\Service\SectionToday;
use App\Classes\Domain\Model\Section;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/** The attendance other modules may read: term totals for card slips, and today's figures for the dashboards. */
interface AttendanceDirectoryInterface
{
    /**
     * Each learner's attendance from $start to $end (both included), counted as SF2 counts its months: only
     * the school days a learner is on the roster, up to today; a school day without a roll call counts as present.
     *
     * @return array<int, AttendanceTally> by enrollment id; learners on none of the months' rosters are left out
     */
    public function tallies(Section $section, \DateTimeImmutable $start, \DateTimeImmutable $end): array;

    /** Whether the section's roll call for $day is in. */
    public function rollCallTaken(Section $section, \DateTimeImmutable $day): bool;

    /** @return array{schoolDay: bool, reason: ?string, sections: list<SectionToday>} the school's attendance today */
    public function schoolToday(School $school, SchoolYear $schoolYear): array;
}
