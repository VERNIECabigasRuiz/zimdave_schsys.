<?php

declare(strict_types=1);

namespace App\Classes\Domain\Model\Repository;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\ClassPeriod;
use App\Classes\Domain\Model\ScheduleEntry;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\TeachingLoad;
use App\Classes\Domain\Model\Weekday;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

interface ScheduleEntryRepositoryInterface
{
    public function findById(int $id): ?ScheduleEntry;

    /**
     * Entries of a section that run in a term (a full-year entry runs in both).
     *
     * @return list<ScheduleEntry>
     */
    public function findForSection(Section $section, int $term): array;

    /**
     * Entries of the school's other sections that run in a term (a full-year entry runs in both),
     * for the "teacher is busy" map.
     *
     * @return list<ScheduleEntry>
     */
    public function findBusy(School $school, SchoolYear $schoolYear, int $term, Section $exceptSection): array;

    /** @return list<ScheduleEntry> */
    public function findForTeacher(User $teacher, SchoolYear $schoolYear): array;

    /** @return list<ScheduleEntry> */
    public function findForTeacherOn(User $teacher, SchoolYear $schoolYear, Weekday $day): array;

    public function findTeacherClash(
        User $teacher,
        SchoolYear $schoolYear,
        Weekday $day,
        ClassPeriod $period,
        int $term,
        Section $exceptSection,
    ): ?ScheduleEntry;

    public function findSectionClash(Section $section, Weekday $day, ClassPeriod $period, int $term): ?ScheduleEntry;

    /** The meetings of a load as saved now, whatever a loaded copy of the load holds. */
    public function countForLoad(TeachingLoad $load): int;

    /** @return list<ScheduleEntry> the meetings of a load as saved now */
    public function findForLoad(TeachingLoad $load): array;

    /** Number of distinct sections that have classes in the period. */
    public function countForPeriod(ClassPeriod $period): int;

    /** @return list<ScheduleEntry> */
    public function findAt(School $school, SchoolYear $schoolYear, Weekday $day, ClassPeriod $period): array;

    public function add(ScheduleEntry $entry): void;

    public function remove(ScheduleEntry $entry): void;
}
