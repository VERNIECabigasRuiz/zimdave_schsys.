<?php

declare(strict_types=1);

namespace App\Classes\Application\Api;

use App\Access\Domain\Model\User;
use App\Classes\Application\Service\Timetable\TimetableQuery;
use App\Classes\Domain\Model\Section;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

final class TimetableDirectory implements TimetableDirectoryInterface
{
    public function __construct(private readonly TimetableQuery $timetable)
    {
    }

    public function termsFor(Section $section): array
    {
        return $this->timetable->termsFor($section);
    }

    public function sectionState(Section $section, int $term): array
    {
        return $this->timetable->sectionState($section, $term);
    }

    public function schoolNow(School $school, SchoolYear $schoolYear, \DateTimeImmutable $at): array
    {
        return $this->timetable->now($school, $schoolYear, $at);
    }

    public function teacherNow(User $teacher, SchoolYear $schoolYear, \DateTimeImmutable $at): array
    {
        return $this->timetable->nowForTeacher($teacher, $schoolYear, $at);
    }
}
