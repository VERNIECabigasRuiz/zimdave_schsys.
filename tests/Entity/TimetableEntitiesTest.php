<?php

namespace App\Tests\Entity;

use App\Entity\ClassPeriod;
use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\ScheduleEntry;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Entity\Weekday;
use App\Repository\ScheduleEntryRepository;
use App\Repository\TeachingLoadRepository;
use App\Tests\SchoolFixturesTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TimetableEntitiesTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private User $teacher;
    private ClassPeriod $period;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS');
        $this->teacher = $this->plainUser('teacher1', User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $this->teacher, User::ROLE_TEACHER);
        $this->period = $this->classPeriod($this->school, $this->sy, '07:30', '08:30');
    }

    private function section(GradeLevel $level, string $name): Section
    {
        $section = (new Section($this->school, $this->sy))->setGradeLevel($level)->setName($name);
        $this->em->persist($section);

        return $section;
    }

    private function subject(GradeLevel $level, string $code, ?int $semester = null): Subject
    {
        $subject = (new Subject($this->school, $this->sy))
            ->setGradeLevel($level)->setCode($code)->setName($code)->setPosition(1)->setMeetingsPerWeek(5)->setSemester($semester);
        $this->em->persist($subject);

        return $subject;
    }

    private function load(Section $section, Subject $subject): TeachingLoad
    {
        $load = new TeachingLoad($section, $subject, $this->teacher);
        $this->em->persist($load);
        $this->em->flush();

        return $load;
    }

    private function entry(TeachingLoad $load, ?ClassPeriod $period = null): ScheduleEntry
    {
        $entry = new ScheduleEntry($load, Weekday::Monday, $period ?? $this->period);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function entries(): ScheduleEntryRepository
    {
        return static::getContainer()->get(ScheduleEntryRepository::class);
    }

    public function testEntryCopiesLoadAndTerm(): void
    {
        $load = $this->load($this->section(GradeLevel::Grade7, 'A'), $this->subject(GradeLevel::Grade7, 'MATH7'));
        $entry = $this->entry($load);
        self::assertSame($load->getSection(), $entry->getSection());
        self::assertSame($this->teacher, $entry->getTeacher());
        self::assertSame($this->sy, $entry->getSchoolYear());
        self::assertSame(0, $entry->getTerm());

        $shs = $this->load($this->section(GradeLevel::Grade11, 'B'), $this->subject(GradeLevel::Grade11, 'ORAL', 2));
        self::assertSame(2, $this->entry($shs)->getTerm());
    }

    public function testSectionSlotUnique(): void
    {
        $section = $this->section(GradeLevel::Grade7, 'A');
        $a = $this->load($section, $this->subject(GradeLevel::Grade7, 'MATH7'));
        $b = $this->load($section, $this->subject(GradeLevel::Grade7, 'SCI7'));
        $this->entry($a);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entry($b);
    }

    public function testRepositoryQueries(): void
    {
        $section = $this->section(GradeLevel::Grade7, 'A');
        $subject = $this->subject(GradeLevel::Grade7, 'MATH7');
        $load = $this->load($section, $subject);
        $entry = $this->entry($load);
        $loads = static::getContainer()->get(TeachingLoadRepository::class);

        self::assertSame([$load], $loads->findForSection($section));
        self::assertSame($load, $loads->findOne($section, $subject));
        self::assertSame(1, $loads->countForSubject($subject));
        self::assertSame(1, $loads->countForTeacher($this->teacher, $this->sy));
        self::assertSame([$load], $loads->findForTeacher($this->teacher, $this->sy));
        self::assertSame([$entry], $this->entries()->findForSection($section, 0));
        self::assertSame([$entry], $this->entries()->findForTeacher($this->teacher, $this->sy));
        self::assertSame($entry, $this->entries()->findSectionClash($section, Weekday::Monday, $this->period, 0));
        self::assertSame(1, $this->entries()->countForPeriod($this->period));
        self::assertSame([$entry], $this->entries()->findAt($this->school, $this->sy, Weekday::Monday, $this->period));
    }

    public function testTeacherClashFindsCrossTerm(): void
    {
        $a = $this->section(GradeLevel::Grade11, 'A');
        $b = $this->section(GradeLevel::Grade11, 'B');
        $other = $this->section(GradeLevel::Grade7, 'C');
        $full = $this->entry($this->load($other, $this->subject(GradeLevel::Grade7, 'MATH7')));

        self::assertSame($full, $this->entries()->findTeacherClash($this->teacher, $this->sy, Weekday::Monday, $this->period, 1, $a));
        self::assertNull($this->entries()->findTeacherClash($this->teacher, $this->sy, Weekday::Monday, $this->period, 1, $other));

        $this->em->remove($full);
        $this->em->flush();
        $first = $this->entry($this->load($b, $this->subject(GradeLevel::Grade11, 'ORAL', 1)));
        self::assertNull($this->entries()->findTeacherClash($this->teacher, $this->sy, Weekday::Monday, $this->period, 2, $a));
        self::assertSame($first, $this->entries()->findTeacherClash($this->teacher, $this->sy, Weekday::Monday, $this->period, 1, $a));
    }
}
