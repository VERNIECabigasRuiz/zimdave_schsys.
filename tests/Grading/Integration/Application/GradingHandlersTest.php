<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Application;

use App\Learners\Domain\Model\Sf1Remark;
use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Grading\Application\Query\Command\GetClassGradesCommand;
use App\Grading\Application\Query\Command\GetFailingLearnersCommand;
use App\Grading\Application\Query\Command\GetGradeSheetCommand;
use App\Grading\Application\Query\Command\GetSchoolGradesCommand;
use App\Grading\Application\Query\Command\GetSectionGradesCommand;
use App\Grading\Application\Query\Command\GetTeacherGradesCommand;
use App\Grading\Application\UseCase\Command\CloseGradingPeriodCommand;
use App\Grading\Application\UseCase\Command\LockClassGradesCommand;
use App\Grading\Application\UseCase\Command\LockGradingPeriodCommand;
use App\Grading\Application\UseCase\Command\OpenGradingPeriodCommand;
use App\Grading\Application\UseCase\Command\SaveGradeSheetCommand;
use App\Grading\Application\UseCase\Command\UnlockClassGradesCommand;
use App\Grading\Application\UseCase\Command\UnlockGradingPeriodCommand;
use App\Grading\Domain\Exception\GradingAccessDeniedException;
use App\Grading\Domain\Exception\GradingNotFoundException;
use App\Grading\Domain\Model\ClassGradeStatus;
use App\Grading\Domain\Model\Grade;
use App\Grading\Domain\Model\GradeLock;
use App\Grading\Domain\Model\GradeOpening;
use App\Learners\Domain\Model\Enrollment;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

final class GradingHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private const BUSY = 'Another change is being saved. Please try again.';

    protected EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private Subject $math;
    private TeachingLoad $load;
    private GradingPeriod $term1;
    private User $teacher;
    private User $adviser;
    private User $principal;
    /** @var list<Enrollment> */
    private array $enrolled = [];

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2025-08-22 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->sy->rebuildGradingPeriods();
        $this->em->flush();
        $this->term1 = $this->sy->getGradingPeriod(1) ?? throw new \LogicException('No first period');
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->teacher = $this->plainUser('cara', User::ROLE_TEACHER);
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->principal = $this->plainUser('rene', User::ROLE_PRINCIPAL);
        $this->assign($this->school, $this->sy, $this->principal);
        $this->assign($this->school, $this->sy, $this->teacher, User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $this->adviser, User::ROLE_TEACHER);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')
            ->setAdviser($this->adviser);
        $this->math = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')
            ->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($this->math);
        $this->load = new TeachingLoad($this->artistic, $this->math, $this->teacher);
        $this->em->persist($this->load);
        foreach (['Arnol', 'Bañez', 'Cruz'] as $i => $name) {
            $this->enrolled[] = $this->enroll(
                $this->learner('12001717000' . $i, $name, 'Learner'),
                $this->sy,
                $this->artistic,
                $this->adviser,
            );
        }
        $this->em->flush();
        $this->openGrading($this->school, $this->term1);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testSavesASheetAndChangesThePeriodThroughTheBus(): void
    {
        // Initialize
        $principalId = (int) $this->principal->getId();
        $schoolId = (int) $this->school->getId();
        $periodId = (int) $this->term1->getId();
        $sectionId = (int) $this->artistic->getId();
        $subjectId = (int) $this->math->getId();

        // Execute
        $results = [
            $this->save([$this->id(0) => 85, $this->id(1) => 90]),
            $this->handle(new LockGradingPeriodCommand($principalId, $schoolId, $periodId)),
            $this->handle(new UnlockClassGradesCommand($principalId, $schoolId, $sectionId, $subjectId, $periodId)),
            $this->handle(new LockClassGradesCommand($principalId, $schoolId, $sectionId, $subjectId, $periodId)),
            $this->handle(new UnlockGradingPeriodCommand($principalId, $schoolId, $periodId)),
            $this->handle(new CloseGradingPeriodCommand($principalId, $schoolId, $periodId)),
            $this->handle(new OpenGradingPeriodCommand($principalId, $schoolId, $periodId)),
        ];

        // Assert
        self::assertSame(
            array_fill(0, 7, true),
            array_map(static fn (Result $r): bool => $r->isSuccess(), $results),
        );
        self::assertSame(2, $results[0]->data());
        $this->em->clear();
        self::assertSame(2, $this->em->getRepository(Grade::class)->count([]));
        self::assertSame(0, $this->em->getRepository(GradeLock::class)->count([]));
        self::assertSame(1, $this->em->getRepository(GradeOpening::class)->count([]));
    }

    public function testAHeldClassOrSchoolLockRefusesASaveAndSavesNothing(): void
    {
        // Initialize
        $factory = static::getContainer()->get(LockFactory::class);
        $classLock = $factory->createLock('grades-' . $this->artistic->getId() . '-' . $this->math->getId());
        $schoolLock = $factory->createLock('grade-lock-' . $this->school->getId());

        // Execute
        $classLock->acquire();
        $whileClassLocked = $this->save([$this->id(0) => 85]);
        $classLock->release();
        $schoolLock->acquire();
        $whileSchoolLocked = $this->save([$this->id(0) => 85]);
        $schoolLock->release();

        // Assert
        foreach ([$whileClassLocked, $whileSchoolLocked] as $refused) {
            self::assertSame('RESOURCE_BUSY', $refused->errorCode());
            self::assertSame(self::BUSY, $refused->exception()?->getMessage());
        }
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Grade::class)->count([]));
    }

    public function testASaveInProgressKeepsThePrincipalFromLockingButNotOtherSaves(): void
    {
        // Initialize
        $saving = static::getContainer()->get(LockFactory::class)->createLock('grade-lock-' . $this->school->getId());
        $saving->acquireRead();

        // Execute
        $lock = $this->handle(new LockGradingPeriodCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            (int) $this->term1->getId(),
        ));
        $save = $this->save([$this->id(0) => 85]);
        $saving->release();

        // Assert
        self::assertSame(self::BUSY, $lock->exception()?->getMessage());
        self::assertTrue($save->isSuccess());
    }

    public function testAGradeChangedAfterThePageWasReadIsSavedUnderTheLock(): void
    {
        // Initialize
        $this->save([$this->id(0) => 85]);
        $this->query(new GetGradeSheetCommand(
            (int) $this->teacher->getId(),
            (int) $this->load->getId(),
            (int) $this->term1->getId(),
        ));
        $connection = $this->em->getConnection();
        $connection->executeStatement('UPDATE grade SET value = 90 WHERE enrollment_id = ?', [$this->id(0)]);

        // Execute
        $saved = $this->save([$this->id(0) => 85]);

        // Assert
        self::assertTrue($saved->isSuccess());
        self::assertEquals(85, $connection->fetchOne(
            'SELECT value FROM grade WHERE enrollment_id = ?',
            [$this->id(0)],
        ));
    }

    public function testRefusesWhatTheAccountOrThePageMayNotReach(): void
    {
        // Initialize
        $admin = $this->plainUser('admin.grades', User::ROLE_ADMIN);
        $schoolId = (int) $this->school->getId();
        $periodId = (int) $this->term1->getId();
        $sectionId = (int) $this->artistic->getId();

        // Execute
        $byAdviser = $this->save([$this->id(0) => 85], $this->adviser);
        $wrongClass = $this->handle(new SaveGradeSheetCommand(
            (int) $this->teacher->getId(),
            (int) $this->load->getId(),
            $periodId,
            $schoolId,
            $sectionId + 1000,
            (int) $this->math->getId(),
            [],
        ));
        $noLoad = $this->query(new GetGradeSheetCommand((int) $this->teacher->getId(), -1, $periodId));
        $adminLocks = $this->handle(new LockGradingPeriodCommand((int) $admin->getId(), $schoolId, $periodId));
        $noPeriod = $this->handle(new OpenGradingPeriodCommand((int) $this->principal->getId(), $schoolId, -1));
        $noSubject = $this->handle(new UnlockClassGradesCommand(
            (int) $this->principal->getId(),
            $schoolId,
            $sectionId,
            -1,
            $periodId,
        ));
        $notAnAdviser = $this->query(new GetClassGradesCommand((int) $this->teacher->getId(), null));
        $unknownPeriod = $this->query(new GetSectionGradesCommand(
            (int) $this->principal->getId(),
            $schoolId,
            $sectionId,
            -1,
        ));

        // Assert
        self::assertInstanceOf(GradingAccessDeniedException::class, $byAdviser->exception());
        self::assertSame('You cannot enter grades for this class.', $byAdviser->exception()->getMessage());
        self::assertInstanceOf(GradingNotFoundException::class, $wrongClass->exception());
        self::assertSame('No such class or grading period.', $noLoad->exception()?->getMessage());
        self::assertInstanceOf(GradingAccessDeniedException::class, $adminLocks->exception());
        self::assertSame('No such grading period.', $noPeriod->exception()?->getMessage());
        self::assertInstanceOf(GradingNotFoundException::class, $noSubject->exception());
        self::assertSame('You do not advise a section this school year.', $notAnAdviser->exception()?->getMessage());
        self::assertSame('No such grading period.', $unknownPeriod->exception()?->getMessage());
    }

    public function testFailingLearnersLeaveOutADroppedLearner(): void
    {
        // Initialize
        $this->save([$this->id(0) => 70]);
        $this->enrolled[0]->setRemarkCode(Sf1Remark::Dropped);
        $this->em->flush();

        // Execute
        $failing = $this->query(new GetFailingLearnersCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            (int) $this->term1->getId(),
        ))->data();

        // Assert
        /** @var array{learners: list<object>} $failing */
        self::assertSame([], $failing['learners']);
    }

    public function testTheQueriesGiveThePagesWhatTheyShow(): void
    {
        // Initialize
        $this->save([$this->id(0) => 70, $this->id(1) => 90]);
        $teacherId = (int) $this->teacher->getId();
        $principalId = (int) $this->principal->getId();
        $schoolId = (int) $this->school->getId();
        $periodId = (int) $this->term1->getId();

        // Execute
        $index = $this->query(new GetTeacherGradesCommand($teacherId))->data();
        $sheet = $this->query(new GetGradeSheetCommand($teacherId, (int) $this->load->getId(), $periodId))->data();
        $school = $this->query(new GetSchoolGradesCommand($principalId, $schoolId, null))->data();
        $failing = $this->query(new GetFailingLearnersCommand($principalId, $schoolId, $periodId))->data();
        $section = $this->query(new GetSectionGradesCommand(
            $principalId,
            $schoolId,
            (int) $this->artistic->getId(),
            $periodId,
        ))->data();
        $class = $this->query(new GetClassGradesCommand((int) $this->adviser->getId(), null))->data();

        // Assert
        /** @var array{graded: list<array{cells: list<array{graded: int, listed: int}>}>} $index */
        self::assertSame(
            [2, 3],
            [$index['graded'][0]['cells'][0]['graded'], $index['graded'][0]['cells'][0]['listed']],
        );
        /** @var array{can_edit: bool, can_request: bool, status: ClassGradeStatus, section_id: int} $sheet */
        self::assertSame(
            [true, false, ClassGradeStatus::Open, (int) $this->artistic->getId()],
            [$sheet['can_edit'], $sheet['can_request'], $sheet['status'], $sheet['section_id']],
        );
        /** @var array{period: GradingPeriod, can_lock: bool, can_open: bool, pending_changes: int} $school */
        self::assertSame(
            [$periodId, true, true, 0],
            [$school['period']->getId(), $school['can_lock'], $school['can_open'], $school['pending_changes']],
        );
        /** @var array{learners: list<object>} $failing */
        self::assertCount(1, $failing['learners']);
        /** @var array{grid: array{columns: list<object>}} $section */
        self::assertCount(1, $section['grid']['columns']);
        /** @var array{section: Section, period: GradingPeriod} $class */
        self::assertSame([$this->artistic, $this->term1], [$class['section'], $class['period']]);
    }

    /** @param array<int, ?int> $values */
    private function save(array $values, ?User $by = null): Result
    {
        return $this->handle(new SaveGradeSheetCommand(
            (int) ($by ?? $this->teacher)->getId(),
            (int) $this->load->getId(),
            (int) $this->term1->getId(),
            (int) $this->school->getId(),
            (int) $this->artistic->getId(),
            (int) $this->math->getId(),
            $values,
        ));
    }

    private function id(int $i): int
    {
        return (int) $this->enrolled[$i]->getId();
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
