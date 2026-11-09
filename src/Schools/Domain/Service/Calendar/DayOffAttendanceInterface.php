<?php

declare(strict_types=1);

namespace App\Schools\Domain\Service\Calendar;

use App\Schools\Domain\Model\School;

/**
 * Attendance already taken on new days off. Implemented by the attendance part of the app.
 */
interface DayOffAttendanceInterface
{
    /**
     * How many of the school's sections took attendance on any of $dates.
     *
     * @param list<\DateTimeImmutable> $dates
     */
    public function countSectionsWithAttendanceOn(School $school, array $dates): int;
}
