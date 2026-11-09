<?php

declare(strict_types=1);

namespace App\Classes\Application\Api;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/**
 * The timetables other modules may read.
 */
interface TimetableDirectoryInterface
{
    /**
     * 0 = full year (Kinder to Grade 10); 1 and 2 = the semesters of Grades 11-12.
     *
     * @return list<int>
     */
    public function termsFor(Section $section): array;

    /**
     * The section's timetable for $term, as the timetable page shows it.
     *
     * @return array<string, mixed>
     */
    public function sectionState(Section $section, int $term): array;

    /**
     * What is happening at $at in the school: the classes running, the teachers with none, or why nothing is.
     *
     * @return array{state: string, period: array{label: string, timeRange: string}|null,
     *     classes: list<array{teacherName: string, subjectName: string, sectionLabel: string}>,
     *     free: list<string>, next: array{label: string, startsAt: string}|null}
     */
    public function schoolNow(School $school, SchoolYear $schoolYear, \DateTimeImmutable $at): array;

    /**
     * The teacher's class at $at and their next one today.
     *
     * @return array{state: string,
     *     current: array{subjectName: string, sectionLabel: string, endsAt: string}|null,
     *     next: array{subjectName: string, sectionLabel: string, startsAt: string}|null}
     */
    public function teacherNow(User $teacher, SchoolYear $schoolYear, \DateTimeImmutable $at): array;
}
