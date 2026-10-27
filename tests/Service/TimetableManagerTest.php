<?php

namespace App\Tests\Service;

use App\Entity\ActivityLog;
use App\Entity\ClassPeriod;
use App\Entity\GradeLevel;
use App\Entity\ScheduleEntry;
use App\Entity\School;
use App\Entity\SchoolType;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Entity\Weekday;
use App\Repository\ClassPeriodRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SubjectRepository;
use App\Repository\TeachingLoadRepository;
use App\Service\ActivityLogger;
use App\Service\PlanLimitException;
use App\Service\PlanLimits;
use App\Service\TimetableException;
use App\Service\TimetableManager;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

class TimetableManagerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private TimetableManager $manager;
    private SchoolYear $sy;
    private School $school;
    private ClassPeriod $p1;
    private ClassPeriod $p2;
    private ClassPeriod $recess;
    private ClassPeriod $p3;
    private Section $rizal;
    private Section $mabini;
    private User $santos;
    private User $reyes;
    private Subject $math7;
    private Subject $science7;
    private Subject $math8;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-16');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $container = static::getContainer();
        // not used by a controller yet, so the test container may have removed it
        $this->manager = $container->has(TimetableManager::class) ? $container->get(TimetableManager::class) : $this->build($container->get(ScheduleEntryRepository::class));
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->p1 = $this->classPeriod($this->school, $this->sy, '07:30', '08:30');
        $this->p2 = $this->classPeriod($this->school, $this->sy, '08:30', '09:30');
        $this->recess = $this->classPeriod($this->school, $this->sy, '09:30', '09:50', 'Recess');
        $this->p3 = $this->classPeriod($this->school, $this->sy, '09:50', '10:50');
        $this->rizal = $this->section(GradeLevel::Grade7, 'Rizal');
        $this->mabini = $this->section(GradeLevel::Grade8, 'Mabini');
        $this->santos = $this->teacher('santos', 'Maria Santos');
        $this->reyes = $this->teacher('reyes', 'Jose Reyes');
        $this->math7 = $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics');
        $this->science7 = $this->subject(GradeLevel::Grade7, 'SCI7', 'Science');
        $this->math8 = $this->subject(GradeLevel::Grade8, 'MATH8', 'Mathematics');
    }

    private function build(ScheduleEntryRepository $entries): TimetableManager
    {
        $container = static::getContainer();

        return new TimetableManager(
            $this->em,
            $container->get(ManagerRegistry::class),
            $container->get(TeachingLoadRepository::class),
            $entries,
            $container->get(SubjectRepository::class),
            $container->get(ClassPeriodRepository::class),
            $container->get(SchoolAssignmentRepository::class),
            $container->get(PlanLimits::class),
            $container->get(ActivityLogger::class),
            $container->get(LockFactory::class),
        );
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

    private function subject(GradeLevel $grade, string $code, string $name, ?int $meetingsPerWeek = 5, ?int $semester = null, ?Subject $parent = null): Subject
    {
        $subject = (new Subject($this->school, $this->sy))
            ->setGradeLevel($grade)->setCode($code)->setName($name)->setPosition(1)
            ->setMeetingsPerWeek($meetingsPerWeek)->setSemester($semester)->setParent($parent);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    /** @return list<string> */
    private function log(): array
    {
        return array_map(static fn (ActivityLog $l) => $l->getAction(), $this->em->getRepository(ActivityLog::class)->findBy([], ['id' => 'ASC']));
    }

    private function entryCount(): int
    {
        return $this->em->getRepository(ScheduleEntry::class)->count([]);
    }

    private function refused(string $message, callable $change): TimetableException
    {
        try {
            $change();
        } catch (TimetableException $e) {
            self::assertSame($message, $e->getMessage());

            return $e;
        }
        self::fail('Expected TimetableException: '.$message);
    }

    public function testSetTeacherCreatesChangesAndClears(): void
    {
        $load = $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        self::assertInstanceOf(TeachingLoad::class, $load);
        self::assertNotNull($load->getId());
        self::assertSame($this->santos, $load->getTeacher());

        $changed = $this->manager->setTeacher($this->rizal, $this->math7, $this->reyes);
        self::assertSame($load, $changed);
        self::assertSame($this->reyes, $changed->getTeacher());

        self::assertNull($this->manager->setTeacher($this->rizal, $this->math7, null));
        self::assertSame(0, $this->em->getRepository(TeachingLoad::class)->count([]));

        self::assertSame([
            'Set teacher santos for MATH7 – Mathematics in Grade 7 – Rizal',
            'Changed teacher to reyes for MATH7 – Mathematics in Grade 7 – Rizal',
            'Cleared teacher for MATH7 – Mathematics in Grade 7 – Rizal',
        ], $this->log());
    }

    public function testTeacherMustBelongToSchool(): void
    {
        $other = $this->school('309999', 'Other NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $outsider = $this->plainUser('cruz', User::ROLE_TEACHER)->setFullName('Ana Cruz');
        $this->assign($other, $this->sy, $outsider, User::ROLE_TEACHER);

        $this->refused('Ana Cruz is not a teacher of this school this school year.', fn () => $this->manager->setTeacher($this->rizal, $this->math7, $outsider));
        self::assertSame(0, $this->em->getRepository(TeachingLoad::class)->count([]));
    }

    public function testSubjectWithComponentsRefused(): void
    {
        $mapeh = $this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH', null);
        $this->subject(GradeLevel::Grade7, 'MU7', 'Music', null, parent: $mapeh);

        $this->refused('MAPEH has components. Pick teachers for its components instead.', fn () => $this->manager->setTeacher($this->rizal, $mapeh, $this->santos));
        self::assertSame(0, $this->em->getRepository(TeachingLoad::class)->count([]));
    }

    public function testSubjectOfOtherGradeRefused(): void
    {
        $this->refused('Mathematics is not a subject of Grade 7 – Rizal.', fn () => $this->manager->setTeacher($this->rizal, $this->math8, $this->santos));
        self::assertSame(0, $this->em->getRepository(TeachingLoad::class)->count([]));
    }

    public function testPlotRefusesWithoutTeacher(): void
    {
        $this->refused('Pick a teacher for Mathematics first.', fn () => $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p1));
        self::assertSame(0, $this->entryCount());
    }

    public function testPlotRefusesWithoutMeetingsPerWeek(): void
    {
        $this->math7->setMeetingsPerWeek(null);
        $this->em->flush();
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);

        $this->refused('Set meetings per week for Mathematics on the Subjects page first.', fn () => $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p1));
        self::assertSame(0, $this->entryCount());
    }

    public function testMeetingsPerWeekLimit(): void
    {
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        foreach (Weekday::cases() as $day) {
            $entry = $this->manager->plot($this->rizal, $this->math7, $day, $this->p1);
            self::assertNotNull($entry->getId());
        }

        $this->refused('Mathematics already has all 5 meetings this week.', fn () => $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p2));
        self::assertSame(5, $this->entryCount());
        self::assertContains('Plotted Mathematics (Maria Santos) in Grade 7 – Rizal, Mon, Period 1', $this->log());
        self::assertContains('Plotted Mathematics (Maria Santos) in Grade 7 – Rizal, Fri, Period 1', $this->log());
    }

    public function testSectionSlotTaken(): void
    {
        $this->manager->setTeacher($this->rizal, $this->science7, $this->reyes);
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        $this->manager->plot($this->rizal, $this->science7, Weekday::Monday, $this->p1);

        $this->refused('Grade 7 – Rizal already has Science on Mon, Period 1.', fn () => $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p1));
        self::assertSame(1, $this->entryCount());
    }

    public function testTeacherBusyElsewhere(): void
    {
        $this->manager->setTeacher($this->mabini, $this->math8, $this->santos);
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        $this->manager->plot($this->mabini, $this->math8, Weekday::Monday, $this->p1);

        $this->refused('Maria Santos already teaches Mathematics in Grade 8 – Mabini on Mon, Period 1.', fn () => $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p1));
        self::assertSame(1, $this->entryCount());
    }

    public function testBackToBackIsFine(): void
    {
        $this->manager->setTeacher($this->mabini, $this->math8, $this->santos);
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        $this->manager->plot($this->mabini, $this->math8, Weekday::Monday, $this->p1);
        $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p2);

        self::assertSame(2, $this->entryCount());
    }

    public function testFullYearClashesWithSemester(): void
    {
        $this->school->setType(SchoolType::Integrated);
        $this->em->flush();
        $grade10 = $this->section(GradeLevel::Grade10, 'Luna');
        $grade11 = $this->section(GradeLevel::Grade11, 'Aguinaldo');
        $math10 = $this->subject(GradeLevel::Grade10, 'MATH10', 'Mathematics');
        $genMath = $this->subject(GradeLevel::Grade11, 'GENMATH', 'General Mathematics', semester: 1);
        $this->manager->setTeacher($grade10, $math10, $this->santos);
        $this->manager->setTeacher($grade11, $genMath, $this->santos);
        $entry = $this->manager->plot($grade10, $math10, Weekday::Monday, $this->p1);
        self::assertSame(0, $entry->getTerm());

        $this->refused('Maria Santos already teaches Mathematics in Grade 10 – Luna on Mon, Period 1.', fn () => $this->manager->plot($grade11, $genMath, Weekday::Monday, $this->p1));
        self::assertSame(1, $this->entryCount());
    }

    public function testBreakRefused(): void
    {
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);

        $this->refused('Recess is a break, not a class period.', fn () => $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->recess));
        self::assertSame(0, $this->entryCount());
    }

    public function testPeriodOfOtherSchoolRefused(): void
    {
        $other = $this->school('309999', 'Other NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $foreign = $this->classPeriod($other, $this->sy, '07:30', '08:30');
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);

        $this->refused("That period is not one of this school's class periods.", fn () => $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $foreign));
        self::assertSame(0, $this->entryCount());
    }

    public function testChangeTeacherWithClashesListsAll(): void
    {
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        foreach (Weekday::cases() as $day) {
            $this->manager->plot($this->rizal, $this->math7, $day, $this->p1);
        }
        $this->manager->setTeacher($this->mabini, $this->math8, $this->reyes);
        $this->manager->plot($this->mabini, $this->math8, Weekday::Monday, $this->p1);
        $this->manager->plot($this->mabini, $this->math8, Weekday::Wednesday, $this->p1);

        $e = $this->refused(
            'Jose Reyes already teaches Mathematics in Grade 8 – Mabini on Mon, Period 1. Jose Reyes already teaches Mathematics in Grade 8 – Mabini on Wed, Period 1.',
            fn () => $this->manager->setTeacher($this->rizal, $this->math7, $this->reyes),
        );
        self::assertSame([
            'Jose Reyes already teaches Mathematics in Grade 8 – Mabini on Mon, Period 1.',
            'Jose Reyes already teaches Mathematics in Grade 8 – Mabini on Wed, Period 1.',
        ], $e->getClashes());

        $this->em->clear();
        $load = $this->em->getRepository(TeachingLoad::class)->findOneBy(['section' => $this->rizal->getId(), 'subject' => $this->math7->getId()]);
        self::assertSame('santos', $load->getTeacher()->getUsername());
        foreach ($load->getEntries() as $entry) {
            self::assertSame('santos', $entry->getTeacher()->getUsername());
        }
    }

    public function testChangeTeacherMovesEntries(): void
    {
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p1);
        $this->manager->plot($this->rizal, $this->math7, Weekday::Tuesday, $this->p2);

        $this->manager->setTeacher($this->rizal, $this->math7, $this->reyes);

        $this->em->clear();
        $entries = $this->em->getRepository(ScheduleEntry::class)->findAll();
        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertSame('reyes', $entry->getTeacher()->getUsername());
            self::assertSame('reyes', $entry->getLoad()->getTeacher()->getUsername());
        }
    }

    public function testClearTeacherWithEntriesRefused(): void
    {
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p1);

        $this->refused('Remove Mathematics from the timetable before clearing its teacher.', fn () => $this->manager->setTeacher($this->rizal, $this->math7, null));
        self::assertSame(1, $this->em->getRepository(TeachingLoad::class)->count([]));
    }

    public function testRemove(): void
    {
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        $entry = $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p3);

        $this->manager->remove($entry);

        self::assertSame(0, $this->entryCount());
        self::assertContains('Removed Mathematics from Grade 7 – Rizal, Mon, Period 3', $this->log());
        // the slot can be used again, and the meeting count went down
        $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p3);
        self::assertSame(1, $this->entryCount());
    }

    public function testUniqueViolationBecomesFriendlyMessage(): void
    {
        $this->manager->setTeacher($this->rizal, $this->science7, $this->santos);
        $this->manager->setTeacher($this->rizal, $this->math7, $this->reyes);
        $this->manager->plot($this->rizal, $this->science7, Weekday::Monday, $this->p1);

        $entries = $this->createStub(ScheduleEntryRepository::class);
        $entries->method('findSectionClash')->willReturn(null);
        $entries->method('findTeacherClash')->willReturn(null);
        $manager = $this->build($entries);

        $this->refused('Another change is being saved. Please try again.', fn () => $manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p1));

        self::assertTrue($this->em->isOpen());
        $left = $this->em->getRepository(ScheduleEntry::class)->findAll();
        self::assertCount(1, $left);
        self::assertSame('SCI7', $left[0]->getLoad()->getSubject()->getCode());
    }

    public function testLockHeldGivesFriendlyMessage(): void
    {
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        $lock = static::getContainer()->get(LockFactory::class)->createLock('school-timetable-'.$this->school->getId());
        self::assertTrue($lock->acquire());

        try {
            $this->refused('Another change is being saved. Please try again.', fn () => $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p1));
        } finally {
            $lock->release();
        }
        self::assertSame(0, $this->entryCount());
    }

    public function testBlockedPlanRefuses(): void
    {
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        $entry = $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p1);
        $this->school->setActive(false);
        $this->em->flush();

        foreach ([
            fn () => $this->manager->setTeacher($this->rizal, $this->science7, $this->santos),
            fn () => $this->manager->plot($this->rizal, $this->math7, Weekday::Tuesday, $this->p1),
            fn () => $this->manager->remove($entry),
        ] as $change) {
            try {
                $change();
                self::fail('Expected PlanLimitException');
            } catch (PlanLimitException $e) {
                self::assertSame('This school is inactive.', $e->getMessage());
            }
        }
        self::assertSame(1, $this->entryCount());
    }
}
