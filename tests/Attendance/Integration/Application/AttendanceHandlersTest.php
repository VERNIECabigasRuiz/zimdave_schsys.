<?php

declare(strict_types=1);

namespace App\Tests\Attendance\Integration\Application;

use App\Access\Domain\Model\User;
use App\Attendance\Application\Query\Command\GetAttendanceMonthCommand;
use App\Attendance\Application\Query\Command\GetAttendanceOverviewCommand;
use App\Attendance\Application\Query\Command\GetRollCallPageCommand;
use App\Attendance\Application\Query\Command\GetSf2FileCommand;
use App\Attendance\Application\UseCase\Command\ReopenSf2Command;
use App\Attendance\Application\UseCase\Command\SubmitSf2Command;
use App\Attendance\Application\UseCase\Command\TakeRollCallCommand;
use App\Attendance\Domain\Exception\AttendanceAccessDeniedException;
use App\Attendance\Domain\Exception\AttendanceException;
use App\Attendance\Domain\Model\LearnerMark;
use App\Attendance\Domain\Model\RollCall;
use App\Attendance\Domain\Model\Sf2Submission;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Classes\Domain\Model\Section;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Learners\Domain\Model\Enrollment;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

final class AttendanceHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private const BUSY = 'Another change is being saved. Please try again.';

    protected EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private Section $honest;
    private User $adviser;
    private User $principal;
    /** @var list<Enrollment> */
    private array $enrolled = [];

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2025-09-02 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $this->adviser, User::ROLE_TEACHER);
        $this->principal = $this->plainUser('rene', User::ROLE_PRINCIPAL);
        $this->assign($this->school, $this->sy, $this->principal);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')
            ->setAdviser($this->adviser);
        $this->honest = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Honest');
        foreach (['Arnol', 'Bañez', 'Cruz'] as $i => $name) {
            $enrollment = new Enrollment(
                $this->learner('12001717000' . $i, $name, 'Learner'),
                $this->sy,
                $this->artistic,
                $this->adviser,
                new \DateTimeImmutable('2025-05-20'),
            );
            $this->em->persist($enrollment);
            $this->enrolled[] = $enrollment;
        }
        $this->em->flush();
    }

    public function testTakesSubmitsAndReopensThroughTheBus(): void
    {
        // Initialize
        $sectionId = (int) $this->artistic->getId();
        $july = new \DateTimeImmutable('2025-07-01');
        $marks = [(int) $this->enrolled[0]->getId() => LearnerMark::Absent];

        // Execute
        $taken = $this->handle(new TakeRollCallCommand(
            (int) $this->adviser->getId(),
            $sectionId,
            new \DateTimeImmutable('2025-09-01'),
            $marks,
        ));
        $submitted = $this->handle(new SubmitSf2Command((int) $this->adviser->getId(), $sectionId, $july, true));
        $reopened = $this->handle(new ReopenSf2Command(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            $sectionId,
            $july,
        ));

        // Assert
        self::assertSame([true, true, true], [$taken->isSuccess(), $submitted->isSuccess(), $reopened->isSuccess()]);
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(RollCall::class)->count([]));
        self::assertSame(0, $this->em->getRepository(Sf2Submission::class)->count([]));
    }

    public function testAHeldLockRefusesTheChangeAndSavesNothing(): void
    {
        // Initialize
        $lock = static::getContainer()->get(LockFactory::class)->createLock(
            'attendance-section-' . $this->artistic->getId(),
        );
        $command = new TakeRollCallCommand(
            (int) $this->adviser->getId(),
            (int) $this->artistic->getId(),
            new \DateTimeImmutable('2025-09-01'),
            [],
        );
        self::assertTrue($lock->acquire());

        // Execute
        try {
            $result = $this->handle($command);
        } finally {
            $lock->release();
        }

        // Assert
        self::assertSame(['RESOURCE_BUSY', self::BUSY], [$result->errorCode(), $result->exception()?->getMessage()]);
        self::assertSame(0, $this->em->getRepository(RollCall::class)->count([]));
    }

    public function testAChangeSavedAfterThePageWasReadIsSeenUnderTheLock(): void
    {
        // Initialize
        $page = $this->query(
            new GetRollCallPageCommand((int) $this->adviser->getId(), new \DateTimeImmutable('2025-09-01')),
        );
        self::assertTrue($page->isSuccess());
        // another request submits September before this one saves the roll call
        $this->em->getConnection()->executeStatement(
            'INSERT INTO sf2_submission (section_id, month, submitted_by_id, submitted_at) VALUES (?, ?, ?, ?)',
            [$this->artistic->getId(), '2025-09-01', $this->adviser->getId(), '2025-09-02 09:00:00'],
        );

        // Execute
        $result = $this->handle(new TakeRollCallCommand(
            (int) $this->adviser->getId(),
            (int) $this->artistic->getId(),
            new \DateTimeImmutable('2025-09-01'),
            [],
        ));

        // Assert
        self::assertSame(
            [AttendanceException::class, 'September 2025 is submitted and locked.'],
            [get_debug_type($result->exception()), $result->exception()?->getMessage()],
        );
    }

    public function testAMarkChangedAfterThePageWasReadIsOverwrittenByTheSave(): void
    {
        // Initialize
        $day = new \DateTimeImmutable('2025-09-01');
        $learnerId = (int) $this->enrolled[0]->getId();
        $rollCall = new RollCall($this->artistic, $day, $this->adviser, new \DateTimeImmutable('2025-09-01 07:30'));
        $rollCall->replaceMarks([$learnerId => [$this->enrolled[0], LearnerMark::Absent]]);
        $this->em->persist($rollCall);
        $this->em->flush();
        $adviserId = (int) $this->adviser->getId();
        $this->query(new GetRollCallPageCommand($adviserId, $day));
        $connection = $this->em->getConnection();
        $connection->executeStatement(
            "UPDATE attendance_mark SET mark = 'late' WHERE roll_call_id = ?",
            [$rollCall->getId()],
        );

        // Execute
        $taken = $this->handle(new TakeRollCallCommand(
            $adviserId,
            (int) $this->artistic->getId(),
            $day,
            [$learnerId => LearnerMark::Absent],
        ));

        // Assert
        self::assertTrue($taken->isSuccess());
        self::assertSame('absent', $connection->fetchOne(
            'SELECT mark FROM attendance_mark WHERE roll_call_id = ?',
            [$rollCall->getId()],
        ));
    }

    public function testRefusesWhatTheAccountOrThePageMayNotReach(): void
    {
        // Initialize
        $outsider = $this->plainUser('cara', User::ROLE_TEACHER);
        $schoolId = (int) $this->school->getId();
        $other = $this->school('303197', 'Mandaue NHS');
        $elsewhere = $this->newSection($other, $this->sy, GradeLevel::Grade7, 'Narra');
        $july = new \DateTimeImmutable('2025-07-01');

        // Execute
        $results = [
            $this->handle(new TakeRollCallCommand((int) $outsider->getId(), (int) $this->artistic->getId(), $july, [])),
            $this->handle(
                new SubmitSf2Command((int) $this->adviser->getId(), (int) $this->honest->getId(), $july, true),
            ),
            $this->handle(
                new ReopenSf2Command((int) $this->adviser->getId(), $schoolId, (int) $this->artistic->getId(), $july),
            ),
            $this->query(
                new GetAttendanceMonthCommand(
                    (int) $this->principal->getId(),
                    $schoolId,
                    (int) $elsewhere->getId(),
                    null,
                ),
            ),
        ];

        // Assert
        self::assertSame(
            [
                [AttendanceAccessDeniedException::class, 'You do not advise a section this school year.'],
                [AttendanceAccessDeniedException::class, 'You do not advise a section this school year.'],
                [AttendanceAccessDeniedException::class, 'Access Denied.'],
                [SectionNotFoundException::class, 'This section is not in the school this school year.'],
            ],
            array_map(
                static fn (Result $r) => [get_debug_type($r->exception()), $r->exception()?->getMessage()],
                $results,
            ),
        );
    }

    public function testTheQueriesGiveThePagesWhatTheyShow(): void
    {
        // Initialize
        $adviserId = (int) $this->adviser->getId();
        $principalId = (int) $this->principal->getId();
        $schoolId = (int) $this->school->getId();
        $august = new \DateTimeImmutable('2025-08-01');

        // Execute
        $rollCall = $this->query(new GetRollCallPageCommand($adviserId, null));
        $adviserMonth = $this->query(new GetAttendanceMonthCommand($adviserId, null, null, $august));
        $schoolMonth = $this->query(
            new GetAttendanceMonthCommand($principalId, $schoolId, (int) $this->artistic->getId(), null),
        );
        $overview = $this->query(new GetAttendanceOverviewCommand($principalId, $schoolId, $august));
        $file = $this->query(new GetSf2FileCommand($adviserId, null, null, $august));

        // Assert
        /** @var array{day: \DateTimeImmutable, refusal: ?string, counts: array<string, int>, nextDay: mixed} $day */
        $day = $rollCall->data();
        self::assertSame(['2025-09-02', null, 3, null], [
            $day['day']->format('Y-m-d'),
            $day['refusal'],
            $day['counts']['present'],
            $day['nextDay'],
        ]);
        /** @var array{canSubmit: bool, canReopen: bool, missingDays: int} $own */
        $own = $adviserMonth->data();
        self::assertSame([true, false, 21], [$own['canSubmit'], $own['canReopen'], $own['missingDays']]);
        /** @var array{canSubmit: bool, canReopen: bool, month: \DateTimeImmutable} $school */
        $school = $schoolMonth->data();
        self::assertSame([false, true, '2025-09-01'], [
            $school['canSubmit'],
            $school['canReopen'],
            $school['month']->format('Y-m-d'),
        ]);
        /** @var array{byGrade: array<string, mixed>} $all */
        $all = $overview->data();
        self::assertSame(['Grade 7', 'Grade 8'], array_keys($all['byGrade']));
        /** @var array{fileName: string, content: string} $sf2 */
        $sf2 = $file->data();
        self::assertSame('SF2_2025_Grade 8 - ARTISTIC_August.xlsx', $sf2['fileName']);
        self::assertStringStartsWith('PK', $sf2['content']);
    }

    public function testAnAdministratorWhoAdvisesASectionFindsTheRollCallClosed(): void
    {
        // Initialize
        $admin = $this->plainUser('admin.adviser', User::ROLE_ADMIN);
        $this->honest->setAdviser($admin);
        $this->em->flush();

        // Execute
        $result = $this->query(new GetRollCallPageCommand((int) $admin->getId(), null));

        // Assert
        /** @var array{refusal: ?string} $page */
        $page = $result->data();
        self::assertSame('You can no longer take attendance for this section.', $page['refusal']);
    }

    public function testAMonthStillAheadHasNoMissingDays(): void
    {
        // Initialize
        $october = new \DateTimeImmutable('2025-10-01');

        // Execute
        $result = $this->query(new GetAttendanceMonthCommand((int) $this->adviser->getId(), null, null, $october));

        // Assert
        /** @var array{canSubmit: bool, missingDays: int} $page */
        $page = $result->data();
        self::assertSame([false, 0], [$page['canSubmit'], $page['missingDays']]);
    }

    public function testAMonthCanBeSubmittedOnceTheSchoolYearHasEnded(): void
    {
        // Initialize
        $this->sy->setEndDate(new \DateTimeImmutable('2025-09-01'));
        $this->em->flush();
        $september = new \DateTimeImmutable('2025-09-01');

        // Execute
        $result = $this->query(new GetAttendanceMonthCommand((int) $this->adviser->getId(), null, null, $september));

        // Assert
        /** @var array{canSubmit: bool} $page */
        $page = $result->data();
        self::assertTrue($page['canSubmit']);
    }

    public function testTheOverviewNeedsACurrentSchoolYear(): void
    {
        // Initialize
        $this->sy->setCurrent(false);
        $this->em->flush();

        // Execute
        $result = $this->query(new GetAttendanceOverviewCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            null,
        ));

        // Assert
        self::assertInstanceOf(SchoolYearNotFoundException::class, $result->exception());
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
