<?php

declare(strict_types=1);

namespace App\Tests\Classes\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Application\Query\Command\GetSectionTimetableCommand;
use App\Classes\Application\Query\Command\GetTeacherLoadCommand;
use App\Classes\Application\UseCase\Command\PlotClassCommand;
use App\Classes\Application\UseCase\Command\RemoveClassCommand;
use App\Classes\Application\UseCase\Command\SetTeachingLoadCommand;
use App\Classes\Domain\Exception\ClassPeriodNotFoundException;
use App\Classes\Domain\Exception\ScheduleEntryNotFoundException;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Classes\Domain\Exception\SubjectNotFoundException;
use App\Classes\Domain\Exception\TimetableException;
use App\Classes\Domain\Model\ClassPeriod;
use App\Classes\Domain\Model\ScheduleEntry;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Domain\Model\ActivityLog;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Exception\TeacherNotFoundException;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

final class TimetableHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private ClassPeriod $p1;
    private Section $rizal;
    private Subject $math7;
    private User $santos;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-16');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Alpha NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->p1 = $this->classPeriod($this->school, $this->sy, '07:30', '08:30');
        $this->rizal = $this->section($this->school, GradeLevel::Grade7, 'Rizal');
        $this->math7 = $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics');
        $this->santos = $this->teacher('santos', 'Maria Santos');
    }

    public function testSetsATeacherPlotsAndRemovesAClass(): void
    {
        // Initialize
        $schoolId = (int) $this->school->getId();
        $sectionId = (int) $this->rizal->getId();
        $subjectId = (int) $this->math7->getId();

        // Execute
        $set = $this->handle(new SetTeachingLoadCommand($schoolId, $sectionId, $subjectId, $this->santos->getId()));
        $plotted = $this->handle(new PlotClassCommand($schoolId, $sectionId, $subjectId, 1, (int) $this->p1->getId()));
        $state = $this->query(new GetSectionTimetableCommand($schoolId, $sectionId));
        $entry = $this->em->getRepository(ScheduleEntry::class)->findOneBy([]);
        $removed = $this->handle(new RemoveClassCommand($schoolId, $sectionId, (int) $entry?->getId()));

        // Assert
        self::assertTrue($set->isSuccess());
        self::assertTrue($plotted->isSuccess());
        self::assertTrue($removed->isSuccess());
        $data = self::timetable($state);
        self::assertSame('Grade 7 – Rizal', $data['section']['label']);
        self::assertSame(0, $data['state']['term']);
        self::assertSame(['Mathematics'], array_column($data['state']['cells'], 'name'));
        self::assertSame(0, $this->em->getRepository(ScheduleEntry::class)->count([]));
        self::assertSame(1, $this->em->getRepository(TeachingLoad::class)->count([]));
        self::assertSame([
            'Set teacher santos for MATH7 – Mathematics in Grade 7 – Rizal',
            'Plotted Mathematics (Maria Santos) in Grade 7 – Rizal, Mon, Period 1',
            'Removed Mathematics from Grade 7 – Rizal, Mon, Period 1',
        ], $this->log());
    }

    public function testAHeldTimetableLockRefusesEveryChange(): void
    {
        // Initialize
        $schoolId = (int) $this->school->getId();
        $sectionId = (int) $this->rizal->getId();
        $subjectId = (int) $this->math7->getId();
        $lock = static::getContainer()->get(LockFactory::class)->createLock('school-timetable-' . $schoolId);
        self::assertTrue($lock->acquire());

        // Execute
        try {
            $results = [
                $this->handle(new SetTeachingLoadCommand($schoolId, $sectionId, $subjectId, $this->santos->getId())),
                $this->handle(new PlotClassCommand($schoolId, $sectionId, $subjectId, 1, (int) $this->p1->getId())),
                $this->handle(new RemoveClassCommand($schoolId, $sectionId, 999999)),
            ];
        } finally {
            $lock->release();
        }

        // Assert
        foreach ($results as $result) {
            self::assertSame(
                ['RESOURCE_BUSY', 'Another change is being saved. Please try again.'],
                [$result->errorCode(), $result->exception()?->getMessage()],
            );
        }
        self::assertSame(0, $this->em->getRepository(TeachingLoad::class)->count([]));
    }

    public function testWhatARequestNamesMustBelongToTheSectionsSchoolAndYear(): void
    {
        // Initialize
        $schoolId = (int) $this->school->getId();
        $sectionId = (int) $this->rizal->getId();
        $subjectId = (int) $this->math7->getId();
        $other = $this->school('303457', 'Beta NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $otherSection = $this->section($other, GradeLevel::Grade7, 'Luna');
        $outsider = $this->plainUser('outsider', User::ROLE_TEACHER);

        // Execute
        $failures = [
            $this->handle(new PlotClassCommand($schoolId, (int) $otherSection->getId(), $subjectId, 1, 1)),
            $this->handle(new PlotClassCommand($schoolId, $sectionId, 999999, 1, (int) $this->p1->getId())),
            $this->handle(new PlotClassCommand($schoolId, $sectionId, $subjectId, 1, 999999)),
            $this->handle(new SetTeachingLoadCommand($schoolId, $sectionId, $subjectId, $outsider->getId())),
            $this->handle(new RemoveClassCommand($schoolId, $sectionId, 999999)),
        ];

        // Assert
        self::assertSame([
            [SectionNotFoundException::class, 'This section is not in the school this school year.'],
            [SubjectNotFoundException::class, 'This subject is not in the school this school year.'],
            [ClassPeriodNotFoundException::class, 'This class period is not in the school this school year.'],
            [TeacherNotFoundException::class, 'This teacher is not in the school this school year.'],
            [ScheduleEntryNotFoundException::class, 'This class is not in the section.'],
        ], array_map(
            static fn (Result $result) => [get_debug_type($result->exception()), $result->exception()?->getMessage()],
            $failures,
        ));
    }

    public function testWithoutACurrentYearTheTimetableIsNotFound(): void
    {
        // Initialize
        $this->sy->setCurrent(false);
        $this->em->flush();

        // Execute
        $result = $this->query(
            new GetSectionTimetableCommand((int) $this->school->getId(), (int) $this->rizal->getId()),
        );

        // Assert
        self::assertInstanceOf(SchoolYearNotFoundException::class, $result->exception());
    }

    public function testAnUnknownTermFallsBackToTheSectionsFirstTerm(): void
    {
        // Initialize
        $schoolId = (int) $this->school->getId();
        $stem = $this->section($this->school, GradeLevel::Grade11, 'STEM-A');

        // Execute
        $wholeYear = $this->query(new GetSectionTimetableCommand($schoolId, (int) $this->rizal->getId(), 2));
        $firstSemester = $this->query(new GetSectionTimetableCommand($schoolId, (int) $stem->getId(), 7));
        $secondSemester = $this->query(new GetSectionTimetableCommand($schoolId, (int) $stem->getId(), 2));

        // Assert
        self::assertSame(
            [0, 1, 2],
            array_map(
                static fn (Result $result) => self::timetable($result)['state']['term'],
                [$wholeYear, $firstSemester, $secondSemester],
            ),
        );
    }

    public function testAClashingTeacherChangeComesBackWithEveryClash(): void
    {
        // Initialize
        $schoolId = (int) $this->school->getId();
        $reyes = $this->teacher('reyes', 'Jose Reyes');
        $mabini = $this->section($this->school, GradeLevel::Grade7, 'Mabini');
        $this->handle(
            new SetTeachingLoadCommand(
                $schoolId,
                (int) $this->rizal->getId(),
                (int) $this->math7->getId(),
                $this->santos->getId(),
            ),
        );
        $this->handle(
            new PlotClassCommand(
                $schoolId,
                (int) $this->rizal->getId(),
                (int) $this->math7->getId(),
                1,
                (int) $this->p1->getId(),
            ),
        );
        $this->handle(
            new SetTeachingLoadCommand($schoolId, (int) $mabini->getId(), (int) $this->math7->getId(), $reyes->getId()),
        );
        $this->handle(
            new PlotClassCommand(
                $schoolId,
                (int) $mabini->getId(),
                (int) $this->math7->getId(),
                1,
                (int) $this->p1->getId(),
            ),
        );

        // Execute
        $result = $this->handle(
            new SetTeachingLoadCommand(
                $schoolId,
                (int) $this->rizal->getId(),
                (int) $this->math7->getId(),
                $reyes->getId(),
            ),
        );

        // Assert
        $failure = $result->exception();
        self::assertInstanceOf(TimetableException::class, $failure);
        self::assertSame('TIMETABLE_REFUSED', $result->errorCode());
        self::assertSame(
            ['Jose Reyes already teaches Mathematics in Grade 7 – Mabini on Mon, Period 1.'],
            $failure->getClashes(),
        );
        $this->em->clear();
        self::assertSame(
            ['santos', 'reyes'],
            array_map(
                static fn (TeachingLoad $load) => $load->getTeacher()->getUsername(),
                $this->em->getRepository(TeachingLoad::class)->findBy([], ['id' => 'ASC']),
            ),
        );
    }

    public function testATeachersLoadShowsASemesterOnlyWhenItHasSemesterSubjects(): void
    {
        // Initialize
        $schoolId = (int) $this->school->getId();
        $reyes = $this->teacher('reyes', 'Jose Reyes');
        $stem = $this->section($this->school, GradeLevel::Grade11, 'STEM-A');
        $oral = $this->subject(GradeLevel::Grade11, 'ORAL', 'Oral Communication')->setSemester(1);
        $reading = $this->subject(GradeLevel::Grade11, 'READ', 'Reading and Writing')->setSemester(2);
        $this->em->flush();
        $this->handle(
            new SetTeachingLoadCommand($schoolId, (int) $stem->getId(), (int) $oral->getId(), $reyes->getId()),
        );
        $this->handle(
            new SetTeachingLoadCommand($schoolId, (int) $stem->getId(), (int) $reading->getId(), $reyes->getId()),
        );
        $this->handle(
            new SetTeachingLoadCommand(
                $schoolId,
                (int) $this->rizal->getId(),
                (int) $this->math7->getId(),
                $this->santos->getId(),
            ),
        );

        // Execute
        $loads = [
            $this->query(new GetTeacherLoadCommand((int) $reyes->getId(), $schoolId, 7)),
            $this->query(new GetTeacherLoadCommand((int) $reyes->getId(), $schoolId, 2)),
            $this->query(new GetTeacherLoadCommand((int) $this->santos->getId(), null, 2)),
        ];

        // Assert
        self::assertSame(
            [
                [1, true, ['Oral Communication']],
                [2, true, ['Reading and Writing']],
                [0, false, ['Mathematics']],
            ],
            array_map(static fn (Result $result) => [
                self::load($result)['term'],
                self::load($result)['hasSemesters'],
                array_column(self::load($result)['week']['loads'] ?? [], 'subjectName'),
            ], $loads),
        );
        self::assertSame(
            [
                'teacher' => ['id' => (int) $this->santos->getId(), 'fullName' => 'Maria Santos'],
                'school' => ['id' => $schoolId, 'name' => 'Alpha NHS'],
                'schoolYear' => ['name' => $this->sy->getName()],
            ],
            array_intersect_key(self::load($loads[2]), array_flip(['teacher', 'school', 'schoolYear'])),
        );
    }

    public function testATeachersLoadElsewhereOrWithoutASchoolYearIsNotFound(): void
    {
        // Initialize
        $other = $this->school('303457', 'Beta NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $santosId = (int) $this->santos->getId();

        // Execute
        $elsewhere = $this->query(new GetTeacherLoadCommand($santosId, (int) $other->getId()));
        $unknown = $this->query(new GetTeacherLoadCommand(999999, (int) $this->school->getId()));
        $this->sy->setCurrent(false);
        $this->em->flush();
        $noYear = $this->query(new GetTeacherLoadCommand($santosId, (int) $this->school->getId()));
        $own = $this->query(new GetTeacherLoadCommand($santosId));

        // Assert
        foreach ([$elsewhere, $unknown, $noYear] as $result) {
            self::assertInstanceOf(TeacherNotFoundException::class, $result->exception());
            self::assertSame('This teacher is not in the school this school year.', $result->exception()->getMessage());
        }
        self::assertSame(
            ['school' => null, 'schoolYear' => null, 'week' => null, 'term' => 0, 'hasSemesters' => false],
            array_diff_key(self::load($own), ['teacher' => true]),
        );
    }

    /** @return array{section: array{label: string}, state: array{term: int, cells: list<array{name: string}>}} */
    private static function timetable(Result $result): array
    {
        /** @var array{section: array{label: string}, state: array{term: int, cells: list<array{name: string}>}} $data */
        $data = $result->data();

        return $data;
    }

    /**
     * @return array{teacher: array{id: int, fullName: string}, school: ?array{id: int, name: string},
     *     schoolYear: ?array{name: string}, week: ?array{loads: list<array{subjectName: string}>}, term: int,
     *     hasSemesters: bool}
     */
    private static function load(Result $result): array
    {
        /** @var array{teacher: array{id: int, fullName: string}, school: ?array{id: int, name: string},
         *     schoolYear: ?array{name: string}, week: ?array{loads: list<array{subjectName: string}>}, term: int,
         *     hasSemesters: bool} $data */
        $data = $result->data();

        return $data;
    }

    private function section(School $school, GradeLevel $grade, string $name): Section
    {
        $section = (new Section($school, $this->sy))->setGradeLevel($grade)->setName($name);
        $this->em->persist($section);
        $this->em->flush();

        return $section;
    }

    private function subject(GradeLevel $grade, string $code, string $name): Subject
    {
        $subject = (new Subject($this->school, $this->sy))
            ->setGradeLevel($grade)->setCode($code)->setName($name)->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function teacher(string $username, string $fullName): User
    {
        $teacher = $this->plainUser($username, User::ROLE_TEACHER)->setFullName($fullName);
        $this->assign($this->school, $this->sy, $teacher, User::ROLE_TEACHER);

        return $teacher;
    }

    /** @return list<string> */
    private function log(): array
    {
        return array_map(
            static fn (ActivityLog $log) => $log->getAction(),
            $this->em->getRepository(ActivityLog::class)->findBy([], ['id' => 'ASC']),
        );
    }

    private function handle(UseCase $command): Result
    {
        return static::getContainer()->get(UseCaseBusInterface::class)->handle($command);
    }

    private function query(Query $command): Result
    {
        return static::getContainer()->get(QueryBusInterface::class)->handle($command);
    }
}
