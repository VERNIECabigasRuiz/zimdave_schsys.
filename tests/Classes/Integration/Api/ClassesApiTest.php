<?php

declare(strict_types=1);

namespace App\Tests\Classes\Integration\Api;

use App\Classes\Domain\Model\Weekday;
use App\Classes\Domain\Model\ScheduleEntry;
use App\Access\Domain\Model\User;
use App\Classes\Application\Api\SectionDirectoryInterface;
use App\Classes\Application\Api\TeachingLoadDirectoryInterface;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The subjects and teaching loads Classes offers other modules. */
final class ClassesApiTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
    }

    public function testFindsASectionsSubjects(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $rizal = $this->newSection($school, $sy, GradeLevel::Grade7, 'Rizal');
        $science = $this->subject($school, $sy, GradeLevel::Grade7, 'SCI7', 'Science', 2);
        $math = $this->subject($school, $sy, GradeLevel::Grade7, 'MATH7', 'Mathematics', 1);
        $this->subject($school, $sy, GradeLevel::Grade8, 'MATH8', 'Mathematics', 1);
        $directory = static::getContainer()->get(SectionDirectoryInterface::class);

        // Execute
        $subjects = $directory->subjectsOf($rizal);
        $found = $directory->findSubject((int) $science->getId());
        $missing = $directory->findSubject(-1);

        // Assert
        self::assertSame([$math, $science], $subjects);
        self::assertSame($science, $found);
        self::assertNull($missing);
    }

    public function testFindsTeachingLoads(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $teacher = $this->plainUser('api-teacher', User::ROLE_TEACHER);
        $other = $this->plainUser('api-other', User::ROLE_TEACHER);
        $rizal = $this->newSection($school, $sy, GradeLevel::Grade7, 'Rizal');
        $bonifacio = $this->newSection($school, $sy, GradeLevel::Grade7, 'Bonifacio');
        $math = $this->subject($school, $sy, GradeLevel::Grade7, 'MATH7', 'Mathematics', 1);
        $science = $this->subject($school, $sy, GradeLevel::Grade7, 'SCI7', 'Science', 2);
        $rizalMath = new TeachingLoad($rizal, $math, $teacher);
        $rizalScience = new TeachingLoad($rizal, $science, $other);
        $bonifacioMath = new TeachingLoad($bonifacio, $math, $teacher);
        foreach ([$rizalMath, $rizalScience, $bonifacioMath] as $load) {
            $this->em->persist($load);
        }
        $this->em->flush();
        $directory = static::getContainer()->get(TeachingLoadDirectoryInterface::class);

        // Execute
        $found = $directory->findLoad((int) $rizalMath->getId());
        $missing = $directory->findLoad(-1);
        $ofSection = $directory->loadsOf($rizal);
        $ofTeacher = $directory->teacherLoadsOf($teacher, $sy);
        $one = $directory->loadOf($bonifacio, $math);
        $none = $directory->loadOf($bonifacio, $science);

        // Assert
        self::assertSame($rizalMath, $found);
        self::assertNull($missing);
        self::assertEqualsCanonicalizing([$rizalMath, $rizalScience], $ofSection);
        self::assertEqualsCanonicalizing([$rizalMath, $bonifacioMath], $ofTeacher);
        self::assertSame($bonifacioMath, $one);
        self::assertNull($none);
    }

    public function testFindsALoadsTimetable(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $teacher = $this->plainUser('api-teacher', User::ROLE_TEACHER);
        $rizal = $this->newSection($school, $sy, GradeLevel::Grade7, 'Rizal');
        $math = $this->subject($school, $sy, GradeLevel::Grade7, 'MATH7', 'Mathematics', 1);
        $science = $this->subject($school, $sy, GradeLevel::Grade7, 'SCI7', 'Science', 2);
        $period = $this->classPeriod($school, $sy, '07:30', '08:30');
        $mathLoad = new TeachingLoad($rizal, $math, $teacher);
        $scienceLoad = new TeachingLoad($rizal, $science, $teacher);
        $this->em->persist($mathLoad);
        $this->em->persist($scienceLoad);
        $this->em->persist(new ScheduleEntry($mathLoad, Weekday::Monday, $period));
        $this->em->persist(new ScheduleEntry($mathLoad, Weekday::Wednesday, $period));
        $this->em->flush();
        $directory = static::getContainer()->get(TeachingLoadDirectoryInterface::class);

        // Execute
        $mathDays = array_map(static fn (ScheduleEntry $entry) => $entry->getDay(), $directory->scheduleOf($mathLoad));
        $scienceDays = $directory->scheduleOf($scienceLoad);

        // Assert
        self::assertEqualsCanonicalizing([Weekday::Monday, Weekday::Wednesday], $mathDays);
        self::assertSame([], $scienceDays);
    }

    private function subject(
        School $school,
        SchoolYear $sy,
        GradeLevel $grade,
        string $code,
        string $name,
        int $position,
    ): Subject {
        $subject = (new Subject($school, $sy))->setGradeLevel($grade)->setCode($code)
            ->setName($name)->setPosition($position)->setMeetingsPerWeek(5);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }
}
