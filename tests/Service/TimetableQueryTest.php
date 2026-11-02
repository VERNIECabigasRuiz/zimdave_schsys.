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

class TimetableQueryTest extends KernelTestCase
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
    private Subject $science7;
    private Subject $math8;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-16');
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
        $this->science7 = $this->subject(GradeLevel::Grade7, 'SCI7', 'Science', 3);
        $this->math8 = $this->subject(GradeLevel::Grade8, 'MATH8', 'Mathematics');
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
    private function sortedKeys(array $map): array
    {
        $keys = array_map('strval', array_keys($map));
        sort($keys);

        return $keys;
    }

    public function testTermsFor(): void
    {
        self::assertSame([0], $this->query->termsFor($this->rizal));
        self::assertSame([1, 2], $this->query->termsFor($this->section(GradeLevel::Grade11, 'Aguinaldo')));
    }

    public function testSectionStateShape(): void
    {
        $this->manager->setTeacher($this->rizal, $this->science7, $this->santos);
        $entry = $this->manager->plot($this->rizal, $this->science7, Weekday::Tuesday, $this->p2);

        $state = $this->query->sectionState($this->rizal, 0);

        self::assertSame(['term', 'terms', 'days', 'periods', 'teachers', 'cards', 'cells', 'busy'], array_keys($state));
        self::assertSame(0, $state['term']);
        self::assertSame([0], $state['terms']);
        self::assertSame([1, 2, 3, 4, 5], array_column($state['days'], 'value'));
        self::assertSame(['value' => 1, 'label' => 'Mon'], $state['days'][0]);
        self::assertSame([
            ['id' => $this->p1->getId(), 'label' => 'Period 1', 'number' => 1, 'isBreak' => false, 'startsAt' => '07:30', 'endsAt' => '08:30'],
            ['id' => $this->recess->getId(), 'label' => 'Recess', 'number' => null, 'isBreak' => true, 'startsAt' => '08:30', 'endsAt' => '08:50'],
            ['id' => $this->p2->getId(), 'label' => 'Period 2', 'number' => 2, 'isBreak' => false, 'startsAt' => '08:50', 'endsAt' => '09:50'],
        ], $state['periods']);
        self::assertContains(['id' => $this->santos->getId(), 'name' => 'Maria Santos'], $state['teachers']);
        self::assertCount(2, $state['teachers']);

        $cards = array_column($state['cards'], null, 'name');
        self::assertSame([
            'subjectId' => $this->science7->getId(), 'name' => 'Science', 'teacherId' => $this->santos->getId(),
            'teacherName' => 'Maria Santos', 'used' => 1, 'perWeek' => 3,
        ], $cards['Science']);
        self::assertNull($cards['Mathematics']['teacherId']);
        self::assertNull($cards['Mathematics']['teacherName']);
        self::assertSame(0, $cards['Mathematics']['used']);

        self::assertSame([[
            'entryId' => $entry->getId(), 'day' => 2, 'periodId' => $this->p2->getId(),
            'subjectId' => $this->science7->getId(), 'name' => 'Science', 'teacherName' => 'Maria Santos',
        ]], $state['cells']);
        self::assertSame([], $state['busy']);
    }

    public function testCardsHideParentsAndOtherSemester(): void
    {
        $mapeh = $this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH', null);
        $this->subject(GradeLevel::Grade7, 'MU7', 'Music', null, parent: $mapeh);
        $names = array_column($this->query->sectionState($this->rizal, 0)['cards'], 'name');
        self::assertContains('Music', $names);
        self::assertNotContains('MAPEH', $names);

        $g11 = $this->section(GradeLevel::Grade11, 'Aguinaldo');
        $this->subject(GradeLevel::Grade11, 'GENMATH', 'General Mathematics', semester: 1);
        $this->subject(GradeLevel::Grade11, 'STAT', 'Statistics', semester: 2);
        self::assertSame(['General Mathematics'], array_column($this->query->sectionState($g11, 1)['cards'], 'name'));
        self::assertSame(['Statistics'], array_column($this->query->sectionState($g11, 2)['cards'], 'name'));
    }

    public function testBusyMapListsOtherSectionsOnly(): void
    {
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        $this->manager->setTeacher($this->mabini, $this->math8, $this->santos);
        $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p1);
        $this->manager->plot($this->mabini, $this->math8, Weekday::Wednesday, $this->p2);

        $busy = $this->query->sectionState($this->rizal, 0)['busy'];

        self::assertSame([(string) $this->santos->getId() => [
            '3-'.$this->p2->getId() => 'Mathematics, Grade 8 – Mabini',
        ]], $busy);
    }

    public function testBusyMapCrossTerm(): void
    {
        $g11 = $this->section(GradeLevel::Grade11, 'Aguinaldo');
        $genMath = $this->subject(GradeLevel::Grade11, 'GENMATH', 'General Mathematics', semester: 1);
        $stat = $this->subject(GradeLevel::Grade11, 'STAT', 'Statistics', semester: 2);
        $this->manager->setTeacher($g11, $genMath, $this->santos);
        $this->manager->setTeacher($g11, $stat, $this->santos);
        $this->manager->setTeacher($this->mabini, $this->math8, $this->santos);
        $this->manager->plot($g11, $genMath, Weekday::Monday, $this->p1);
        $this->manager->plot($g11, $stat, Weekday::Tuesday, $this->p1);
        $this->manager->plot($this->mabini, $this->math8, Weekday::Friday, $this->p1);
        $id = (string) $this->santos->getId();
        $p1 = $this->p1->getId();

        // a full-year section sees both semesters
        $busy = $this->query->sectionState($this->rizal, 0)['busy'][$id];
        self::assertSame(['1-'.$p1, '2-'.$p1, '5-'.$p1], $this->sortedKeys($busy));

        // a semester-1 section sees full-year and semester 1, not semester 2
        $other = $this->section(GradeLevel::Grade12, 'Luna');
        $busy = $this->query->sectionState($other, 1)['busy'][$id];
        self::assertSame(['1-'.$p1, '5-'.$p1], $this->sortedKeys($busy));
        self::assertSame('Mathematics, Grade 8 – Mabini', $busy['5-'.$p1]);
    }

    public function testTeacherWeekTotals(): void
    {
        $g11 = $this->section(GradeLevel::Grade11, 'Aguinaldo');
        $genMath = $this->subject(GradeLevel::Grade11, 'GENMATH', 'General Mathematics', 4, 1);
        $this->manager->setTeacher($this->rizal, $this->math7, $this->santos);
        $this->manager->setTeacher($g11, $genMath, $this->santos);
        $this->manager->plot($this->rizal, $this->math7, Weekday::Monday, $this->p1);
        $this->manager->plot($this->rizal, $this->math7, Weekday::Tuesday, $this->p1);
        $this->manager->plot($g11, $genMath, Weekday::Wednesday, $this->p1);

        $week = $this->query->teacherWeek($this->santos, $this->sy, 1);
        self::assertSame(['periods', 'days', 'cells', 'loads', 'totalPerWeek'], array_keys($week));
        self::assertSame(3, $week['totalPerWeek']);
        self::assertCount(3, $week['periods']);
        self::assertCount(3, $week['cells']);
        self::assertContains(['day' => 1, 'periodId' => $this->p1->getId(), 'name' => 'Mathematics', 'sectionLabel' => 'Grade 7 – Rizal'], $week['cells']);
        self::assertContains(['subjectName' => 'General Mathematics', 'sectionLabel' => 'Grade 11 – Aguinaldo', 'perWeek' => 4, 'semester' => 1], $week['loads']);
        self::assertContains(['subjectName' => 'Mathematics', 'sectionLabel' => 'Grade 7 – Rizal', 'perWeek' => 5, 'semester' => null], $week['loads']);

        // semester 2: the full-year classes still count, the semester-1 class does not
        $week2 = $this->query->teacherWeek($this->santos, $this->sy, 2);
        self::assertSame(2, $week2['totalPerWeek']);
        self::assertCount(1, $week2['loads']);
    }
}
