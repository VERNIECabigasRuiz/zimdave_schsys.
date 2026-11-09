<?php

declare(strict_types=1);

namespace App\Attendance\Infrastructure\Schools;

use App\Attendance\Domain\Model\Repository\RollCallRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Service\Calendar\DayOffAttendanceInterface;

class RollCallDayOffAttendance implements DayOffAttendanceInterface
{
    public function __construct(private readonly RollCallRepositoryInterface $rollCalls)
    {
    }

    public function countSectionsWithAttendanceOn(School $school, array $dates): int
    {
        return $this->rollCalls->countSectionsOn($school, $dates);
    }
}
