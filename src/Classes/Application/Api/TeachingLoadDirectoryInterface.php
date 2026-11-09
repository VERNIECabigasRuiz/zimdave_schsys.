<?php

declare(strict_types=1);

namespace App\Classes\Application\Api;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\ScheduleEntry;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Schools\Domain\Model\SchoolYear;

/**
 * The teaching loads other modules may read: who teaches which subject to which section.
 */
interface TeachingLoadDirectoryInterface
{
    public function findLoad(int $id): ?TeachingLoad;

    /** @return list<TeachingLoad> */
    public function loadsOf(Section $section): array;

    /** @return list<TeachingLoad> */
    public function teacherLoadsOf(User $teacher, SchoolYear $schoolYear): array;

    /** The load of $subject in $section, if anyone teaches it. */
    public function loadOf(Section $section, Subject $subject): ?TeachingLoad;

    /** @return list<ScheduleEntry> the load's meetings in the timetable */
    public function scheduleOf(TeachingLoad $load): array;
}
