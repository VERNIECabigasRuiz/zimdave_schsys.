<?php

namespace App\Tests\Service;

use App\Entity\ClassPeriod;
use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolType;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use App\Entity\Weekday;
use App\Repository\ClassPeriodRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SubjectRepository;
use App\Repository\TeachingLoadRepository;
use App\Service\ActivityLogger;
use App\Service\PlanLimits;
use App\Service\TimetableManager;
use App\Service\TimetableQuery;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

class TimetableNowTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private TimetableManager $manager;
    private TimetableQuery $query;
    private SchoolYear $sy;
    private School $school;
    private ClassPeriod $p1;
    private ClassPeriod $recess;
    private ClassPeriod $p2;
    private Section $rizal;
    private Section $mabini;
    private User $santos;
    private User $reyes;
    private Subject $math7;
    private Subject $science8;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->manager = new TimetableManager(
            $this->em, $c->get(ManagerRegistry::class), $c->get(TeachingLoadRepository::class), $c->get(ScheduleEntryRepository::class),
            $c->get(SubjectRepository::class), $c->get(ClassPeriodRepository::class), $c->get(SchoolAssignmentRepository::class),
            $c->get(PlanLimits::class), $c->get(ActivityLogger::class), $c->get(LockFactory::class),
        );
        $this->query = new TimetableQuery(
            $c->get(ScheduleEntryRepository::class), $c->get(TeachingLoadRepository::class), $c->get(SubjectRepository::class),
            $c->get(ClassPeriodRepository::class), $c->get(SchoolAssignmentRepository::class),
        );
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->school->setType(SchoolType::Integrated);
        $this->em->flush();
        $this->p1 = $this->classPeriod($this->school, $this->sy, '07:30', '08:30');
        $this->recess = $this->classPeriod($this->school, $this->sy, '08:30', '08:50', 'Recess');
        $this->p2 = $this->classPeriod($this->school, $this->sy, '08:50', '09:50');
        $this->rizal = $this->section(GradeLevel::Grade7, 'Rizal');
        $this->mabini = $this->section(GradeLevel::Grade8, 'Mabini');
        $this->santos = $this->teacher('santos', 'Maria Santos');
        $this->reyes = $this->teacher('reyes', 'Jose Reyes');
        $this->math7 = $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics');
        $this->science8 = $this->subject(GradeLevel::Grade8, 'SCI8', 'Science');
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        $this->manager->setTeacher($this->mabini, $this->science8, $this->santos);
        // Monday: Santos teaches Math in Period 1 and Science in Period 2
        $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p1);
        $this->manager->plot($this->mabini, $this->science8, Weekday::Monday, $this->p2);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function section(GradeLevel $grade, string $name): Section
    {
        $section = (new Section($this->school, $this->sy))->setGradeLevel($grade)->setName($name);
        $this->em->persist($section);
        $this->em->flush();

        return $section;
    }

    private function teacher(string $username, string $fullName): User
    {
        $teacher = $this->plainUser($username, User::ROLE_TEACHER)->setFullName($fullName);
        $this->assign($this->school, $this->sy, $teacher, User::ROLE_TEACHER);

        return $teacher;
    }

    private function subject(GradeLevel $grade, string $code, string $name): Subject
    {
        $subject = (new Subject($this->school, $this->sy))
            ->setGradeLevel($grade)->setCode($code)->setName($name)->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time.' Asia/Manila');
    }

    public function testDuringPeriodListsClassesAndFreeTeachers(): void
    {
        $now = $this->query->now($this->school, $this->sy, $this->at('2026-10-19 08:00'));

        self::assertSame('class', $now['state']);
        self::assertSame('Period 1', $now['period']['label']);
        self::assertSame('07:30–08:30', $now['period']['timeRange']);
        self::assertSame([['teacherName' => 'Maria Santos', 'subjectName' => 'Mathematics', 'sectionLabel' => 'Grade 7 – Rizal']], $now['classes']);
        self::assertSame(['Jose Reyes'], $now['free']);
    }

    public function testDuringBreak(): void
    {
        $now = $this->query->now($this->school, $this->sy, $this->at('2026-10-19 08:35'));

        self::assertSame('break', $now['state']);
        self::assertSame('Recess', $now['period']['label']);
        self::assertSame([], $now['classes']);
        self::assertSame('Period 2', $now['next']['label']);
        self::assertSame('08:50', $now['next']['startsAt']);
    }

    public function testBeforeFirstAndAfterLast(): void
    {
        $before = $this->query->now($this->school, $this->sy, $this->at('2026-10-19 06:45'));
        self::assertSame('before', $before['state']);
        self::assertNull($before['period']);
        self::assertSame(['label' => 'Period 1', 'startsAt' => '07:30'], $before['next']);

        $after = $this->query->now($this->school, $this->sy, $this->at('2026-10-19 09:50'));
        self::assertSame('after', $after['state']);
        self::assertNull($after['next']);
    }

    public function testWeekend(): void
    {
        $now = $this->query->now($this->school, $this->sy, $this->at('2026-10-24 08:00'));

        self::assertSame('weekend', $now['state']);
        self::assertSame([], $now['classes']);
    }

    public function testNoPeriods(): void
    {
        $this->em->createQuery('DELETE FROM '.\App\Entity\ScheduleEntry::class)->execute();
        $this->em->createQuery('DELETE FROM '.ClassPeriod::class)->execute();

        self::assertSame('no_periods', $this->query->now($this->school, $this->sy, $this->at('2026-10-19 08:00'))['state']);
    }

    public function testBoundaryBelongsToNextPeriod(): void
    {
        $now = $this->query->now($this->school, $this->sy, $this->at('2026-10-19 08:50'));

        self::assertSame('class', $now['state']);
        self::assertSame('Period 2', $now['period']['label']);
        self::assertSame(['Science'], array_column($now['classes'], 'subjectName'));
        self::assertSame(['Jose Reyes'], $now['free']);
    }

    public function testTeacherNowAndNext(): void
    {
        $now = $this->query->nowForTeacher($this->santos, $this->sy, $this->at('2026-10-19 08:00'));

        self::assertSame('class', $now['state']);
        self::assertSame(['subjectName' => 'Mathematics', 'sectionLabel' => 'Grade 7 – Rizal', 'endsAt' => '08:30'], $now['current']);
        self::assertSame(['subjectName' => 'Science', 'sectionLabel' => 'Grade 8 – Mabini', 'startsAt' => '08:50'], $now['next']);
    }

    public function testTeacherDoneForTheDay(): void
    {
        $now = $this->query->nowForTeacher($this->santos, $this->sy, $this->at('2026-10-19 09:50'));

        self::assertSame('done', $now['state']);
        self::assertNull($now['current']);
        self::assertNull($now['next']);
    }

    public function testTeacherFreePeriodShowsNextClass(): void
    {
        $now = $this->query->nowForTeacher($this->santos, $this->sy, $this->at('2026-10-19 08:40'));
        self::assertSame('break', $now['state']);
        self::assertSame('Science', $now['next']['subjectName']);

        $this->em->createQuery('DELETE FROM '.\App\Entity\ScheduleEntry::class.' e WHERE e.period = :p')->setParameter('p', $this->p1)->execute();
        $free = $this->query->nowForTeacher($this->santos, $this->sy, $this->at('2026-10-19 08:00'));
        self::assertSame('free', $free['state']);
        self::assertNull($free['current']);
        self::assertSame('08:50', $free['next']['startsAt']);
    }

    public function testTeacherWeekend(): void
    {
        self::assertSame('weekend', $this->query->nowForTeacher($this->santos, $this->sy, $this->at('2026-10-24 08:00'))['state']);
    }
}

