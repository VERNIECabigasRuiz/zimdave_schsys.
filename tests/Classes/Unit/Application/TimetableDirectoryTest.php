<?php

declare(strict_types=1);

namespace App\Tests\Classes\Unit\Application;

use App\Access\Domain\Model\User;
use App\Classes\Application\Api\TimetableDirectory;
use App\Classes\Application\Service\Timetable\TimetableQuery;
use App\Classes\Domain\Model\Section;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use PHPUnit\Framework\TestCase;

/** The timetable API passes each call to the timetable's read side. */
final class TimetableDirectoryTest extends TestCase
{
    public function testPassesEachCallToTheTimetable(): void
    {
        // Initialize
        $school = new School();
        $schoolYear = new SchoolYear();
        $section = new Section($school, $schoolYear);
        $teacher = new User();
        $at = new \DateTimeImmutable('2026-11-09 09:00');
        $schoolNow = ['state' => 'class', 'period' => null, 'classes' => [], 'free' => [], 'next' => null];
        $teacherNow = ['state' => 'free', 'current' => null, 'next' => null];
        $timetable = $this->createMock(TimetableQuery::class);
        $timetable->expects(self::once())->method('termsFor')->with($section)->willReturn([1, 2]);
        $timetable->expects(self::once())->method('sectionState')->with($section, 2)->willReturn(['term' => 2]);
        $timetable->expects(self::once())->method('now')->with($school, $schoolYear, $at)->willReturn($schoolNow);
        $timetable->expects(self::once())->method('nowForTeacher')->with($teacher, $schoolYear, $at)
            ->willReturn($teacherNow);
        $directory = new TimetableDirectory($timetable);

        // Execute
        $terms = $directory->termsFor($section);
        $state = $directory->sectionState($section, 2);
        $now = $directory->schoolNow($school, $schoolYear, $at);
        $teacherView = $directory->teacherNow($teacher, $schoolYear, $at);

        // Assert
        self::assertSame([1, 2], $terms);
        self::assertSame(['term' => 2], $state);
        self::assertSame($schoolNow, $now);
        self::assertSame($teacherNow, $teacherView);
    }
}
