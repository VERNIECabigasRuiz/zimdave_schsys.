<?php

namespace App\Service\SchoolsHooks;

use App\Repository\RollCallRepository;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Service\Calendar\DayOffAttendanceInterface;

class RollCallDayOffAttendance implements DayOffAttendanceInterface
{
    public function __construct(private readonly RollCallRepository $rollCalls)
    {
    }

    public function countSectionsWithAttendanceOn(School $school, array $dates): int
    {
        return $this->rollCalls->countSectionsOn($school, $dates);
    }
}
