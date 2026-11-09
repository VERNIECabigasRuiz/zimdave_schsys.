<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Grading\Application\Query\Command\GetGradeChangeCommand;
use App\Grading\Application\Query\Command\GetGradeChangeFormCommand;
use App\Grading\Application\Query\Command\GetGradeChangesCommand;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\UseCase\Command\ApproveGradeChangeCommand;
use App\Grading\Application\UseCase\Command\DeclineGradeChangeCommand;
use App\Grading\Application\UseCase\Command\RequestGradeChangeCommand;
use App\Grading\Application\UseCase\Command\WithdrawGradeChangeCommand;
use App\Grading\Domain\Exception\GradingAccessDeniedException;
use App\Grading\Domain\Exception\GradingNotFoundException;
use App\Grading\Domain\Model\GradeChangeStatus;
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

final class GradeChangeHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SavesGrades;
    use SchoolFixturesTrait;

    private const BUSY = 'Another change is being saved. Please try again.';
    private const REASON = 'Encoding error on the second quiz.';

    protected EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private Subject $math;
    private TeachingLoad $load;
    private GradingPeriod $term1;
    private User $teacher;
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
        $this->term1 = $this->sy->getGradingPeriod(1) ?? throw new \LogicException('No period 1');
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->teacher = $this->plainUser('cara', User::ROLE_TEACHER);
        $this->principal = $this->plainUser('rene', User::ROLE_PRINCIPAL);
        $this->assign($this->school, $this->sy, $this->principal);
        $this->assign($this->school, $this->sy, $this->teacher, User::ROLE_TEACHER);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic');
        $this->math = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')
            ->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($this->math);
        $this->load = new TeachingLoad($this->artistic, $this->math, $this->teacher);
        $this->em->persist($this->load);
        foreach (['Arnol', 'Bañez'] as $i => $name) {
            $this->enrolled[] = $this->enroll(
                $this->learner('12001717000' . $i, $name, 'Learner'),
                $this->sy,
                $this->artistic,
                $this->teacher,
            );
        }
        $this->em->flush();
        $this->openGrading($this->school, $this->term1);
        $this->savingRecorder()->saveSheet($this->load, $this->term1, [$this->id(0) => 80], $this->teacher);
        $this->savingRecorder()->lockPeriod($this->school, $this->term1, $this->principal);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testSendsApprovesDeclinesAndWithdrawsThroughTheBus(): void
    {
        // Initialize
        $principalId = (int) $this->principal->getId();
        $schoolId = (int) $this->school->getId();

        // Execute
        $sent = $this->send([$this->id(0) => 85]);
        $approved = $this->approve($this->latestId());
        $sentAgain = $this->send([$this->id(0) => 90]);
        $declined = $this->handle(
            new DeclineGradeChangeCommand($principalId, $schoolId, $this->latestId(), 'Not enough proof'),
        );
        $sentThird = $this->send([$this->id(1) => 88]);
        $withdrawn = $this->handle(
            new WithdrawGradeChangeCommand((int) $this->teacher->getId(), $this->latestId(), $schoolId),
        );

        // Assert
        self::assertSame(
            array_fill(0, 6, true),
            array_map(
                static fn (Result $r): bool => $r->isSuccess(),
                [$sent, $approved, $sentAgain, $declined, $sentThird, $withdrawn],
            ),
        );
        self::assertEquals(85, $this->gradeOf(0));
        self::assertSame(
            ['approved', 'declined', 'withdrawn'],
            $this->em->getConnection()->fetchFirstColumn('SELECT status FROM grade_change_request ORDER BY id'),
        );
    }

    public function testHeldLocksRefuseAChange(): void
    {
        // Initialize
        $factory = static::getContainer()->get(LockFactory::class);
        $classLock = $factory->createLock('grades-' . $this->artistic->getId() . '-' . $this->math->getId());
        $schoolLock = $factory->createLock('grade-lock-' . $this->school->getId());

        // Execute
        $classLock->acquire();
        $whileClassLocked = $this->send([$this->id(0) => 85]);
        $classLock->release();
        $this->send([$this->id(0) => 85]);
        $schoolLock->acquire();
        $sendWhileSchoolLocked = $this->send([$this->id(1) => 85]);
        $declineWhileSchoolLocked = $this->handle(new DeclineGradeChangeCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            $this->latestId(),
            'Not enough proof',
        ));
        $schoolLock->release();

        // Assert
        foreach ([$whileClassLocked, $sendWhileSchoolLocked, $declineWhileSchoolLocked] as $refused) {
            self::assertSame(self::BUSY, $refused->exception()?->getMessage());
        }
        self::assertSame(
            ['pending'],
            $this->em->getConnection()->fetchFirstColumn('SELECT status FROM grade_change_request'),
        );
    }

    public function testARequestDecidedAfterThePageWasReadIsSeenUnderTheLock(): void
    {
        // Initialize
        $this->send([$this->id(0) => 85]);
        $requestId = $this->latestId();
        $this->query(new GetGradeChangeCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            $requestId,
            GradePolicy::CHANGE_DECIDE,
        ));
        $this->em->getConnection()->executeStatement(
            'UPDATE grade_change_request SET status = ? WHERE id = ?',
            [GradeChangeStatus::Withdrawn->value, $requestId],
        );

        // Execute
        $approved = $this->approve($requestId);

        // Assert
        self::assertSame('This request has already been decided.', $approved->exception()?->getMessage());
        self::assertEquals(80, $this->gradeOf(0));
    }

    public function testAGradeChangedAfterTheRequestWasSentRefusesTheApproval(): void
    {
        // Initialize
        $this->send([$this->id(0) => 85]);
        $requestId = $this->latestId();
        $this->em->getConnection()->executeStatement(
            'UPDATE grade SET value = 70 WHERE enrollment_id = ?',
            [$this->id(0)],
        );

        // Execute
        $approved = $this->approve($requestId);

        // Assert
        self::assertSame(
            'Grades have changed since this request was sent. Decline it so the teacher can send a new one.',
            $approved->exception()?->getMessage(),
        );
    }

    public function testRefusesWhatTheAccountMayNotDo(): void
    {
        // Initialize
        $admin = $this->plainUser('admin.changes', User::ROLE_ADMIN);
        $other = $this->school('303197', 'Other NHS');
        $this->send([$this->id(0) => 85]);
        $requestId = $this->latestId();

        // Execute
        $adminApproves = $this->approve($requestId, $admin);
        $otherSchool = $this->query(new GetGradeChangeCommand(
            (int) $this->principal->getId(),
            (int) $other->getId(),
            $requestId,
            GradePolicy::CHANGE_DECIDE,
        ));
        $principalWithdraws = $this->handle(new WithdrawGradeChangeCommand(
            (int) $this->principal->getId(),
            $requestId,
            (int) $this->school->getId(),
        ));
        $principalForm = $this->query(new GetGradeChangeFormCommand(
            (int) $this->principal->getId(),
            (int) $this->load->getId(),
            (int) $this->term1->getId(),
        ));

        // Assert
        self::assertInstanceOf(GradingAccessDeniedException::class, $adminApproves->exception());
        self::assertInstanceOf(GradingNotFoundException::class, $otherSchool->exception());
        self::assertInstanceOf(GradingAccessDeniedException::class, $principalWithdraws->exception());
        self::assertInstanceOf(GradingAccessDeniedException::class, $principalForm->exception());
    }

    public function testIdsThatDoNotBelongTogetherAreNotFound(): void
    {
        // Initialize
        $other = $this->school('303197', 'Other NHS');
        $this->send([$this->id(0) => 85]);
        $requestId = $this->latestId();
        $sectionId = (int) $this->artistic->getId();
        $subjectId = (int) $this->math->getId();

        // Execute
        $wrongSection = $this->handle(new RequestGradeChangeCommand(
            (int) $this->teacher->getId(),
            (int) $this->load->getId(),
            (int) $this->term1->getId(),
            (int) $this->school->getId(),
            $sectionId + 1000,
            $subjectId,
            [$this->id(1) => 90],
            self::REASON,
        ));
        $wrongSubject = $this->handle(new ApproveGradeChangeCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            $requestId,
            $sectionId,
            $subjectId + 1000,
        ));
        $wrongSchool = $this->handle(new WithdrawGradeChangeCommand(
            (int) $this->teacher->getId(),
            $requestId,
            (int) $other->getId(),
        ));

        // Assert
        self::assertInstanceOf(GradingNotFoundException::class, $wrongSection->exception());
        self::assertInstanceOf(GradingNotFoundException::class, $wrongSubject->exception());
        self::assertInstanceOf(GradingNotFoundException::class, $wrongSchool->exception());
        self::assertSame(GradeChangeStatus::Pending, $this->statusOf($requestId));
    }

    private function statusOf(int $requestId): GradeChangeStatus
    {
        $status = $this->em->getConnection()->fetchOne(
            'SELECT status FROM grade_change_request WHERE id = ?',
            [$requestId],
        );
        self::assertIsString($status);

        return GradeChangeStatus::from($status);
    }

    public function testTheQueriesGiveThePagesWhatTheyShow(): void
    {
        // Initialize
        $this->send([$this->id(0) => 85]);
        $requestId = $this->latestId();

        // Execute
        $form = $this->query(new GetGradeChangeFormCommand(
            (int) $this->teacher->getId(),
            (int) $this->load->getId(),
            (int) $this->term1->getId(),
        ))->data();
        $change = $this->query(new GetGradeChangeCommand(
            (int) $this->teacher->getId(),
            null,
            $requestId,
            GradePolicy::CHANGE_WITHDRAW,
        ))->data();
        $list = $this->query(new GetGradeChangesCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            'pending',
        ))->data();

        // Assert
        /** @var array{refusal: ?string, section_id: int} $form */
        self::assertSame(
            ['You already have a pending change request for this class.', (int) $this->artistic->getId()],
            [$form['refusal'], $form['section_id']],
        );
        /** @var array{pending: bool, sheet: array{load: int, period: int}|null} $change */
        self::assertSame(
            [true, ['load' => (int) $this->load->getId(), 'period' => (int) $this->term1->getId()]],
            [$change['pending'], $change['sheet']],
        );
        /** @var array{decidable: list<int>, pending_count: int, class_labels: array<int, string>} $list */
        self::assertSame([[$requestId], 1], [$list['decidable'], $list['pending_count']]);
        self::assertSame('Mathematics – Grade 8 – ARTISTIC', $list['class_labels'][$requestId]);
    }

    /** @param array<int, ?int> $values */
    private function send(array $values): Result
    {
        return $this->handle(new RequestGradeChangeCommand(
            (int) $this->teacher->getId(),
            (int) $this->load->getId(),
            (int) $this->term1->getId(),
            (int) $this->school->getId(),
            (int) $this->artistic->getId(),
            (int) $this->math->getId(),
            $values,
            self::REASON,
        ));
    }

    private function approve(int $requestId, ?User $by = null): Result
    {
        return $this->handle(new ApproveGradeChangeCommand(
            (int) ($by ?? $this->principal)->getId(),
            (int) $this->school->getId(),
            $requestId,
            (int) $this->artistic->getId(),
            (int) $this->math->getId(),
        ));
    }

    private function latestId(): int
    {
        $id = $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM grade_change_request');
        self::assertIsNumeric($id);

        return (int) $id;
    }

    private function gradeOf(int $i): mixed
    {
        return $this->em->getConnection()->fetchOne('SELECT value FROM grade WHERE enrollment_id = ?', [$this->id($i)]);
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
