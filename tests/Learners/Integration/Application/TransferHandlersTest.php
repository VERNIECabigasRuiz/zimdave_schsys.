<?php

declare(strict_types=1);

namespace App\Tests\Learners\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Learners\Application\Query\Command\GetTransferCommand;
use App\Learners\Application\Query\Command\GetTransferRequestFormCommand;
use App\Learners\Application\Query\Command\ListTransfersCommand;
use App\Learners\Application\UseCase\Command\ApproveTransferCommand;
use App\Learners\Application\UseCase\Command\CancelTransferCommand;
use App\Learners\Application\UseCase\Command\DeclineTransferCommand;
use App\Learners\Application\UseCase\Command\RequestTransferCommand;
use App\Learners\Domain\Exception\EnrollmentException;
use App\Learners\Domain\Exception\LearnersAccessDeniedException;
use App\Learners\Domain\Exception\TransferLookupChangedException;
use App\Learners\Domain\Exception\TransferNotFoundException;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\TransferRequest;
use App\Learners\Domain\Model\TransferStatus;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

final class TransferHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private const BUSY = 'Another change is being saved. Please try again.';
    private const DECIDED = 'This request was already decided.';

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $from;
    private School $to;
    private Section $rizal;
    private Section $bonifacio;
    private User $sender;
    private User $receiver;
    private User $admin;
    private Learner $learner;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-16');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->from = $this->school('303456', 'Pagsabungan NHS');
        $this->to = $this->school('303457', 'Mandaue NHS');
        $this->rizal = $this->newSection($this->from, $this->sy, GradeLevel::Grade7, 'Rizal');
        $this->bonifacio = $this->newSection($this->to, $this->sy, GradeLevel::Grade7, 'Bonifacio');
        $this->sender = $this->plainUser('maria', User::ROLE_PRINCIPAL);
        $this->assign($this->from, $this->sy, $this->sender);
        $this->receiver = $this->plainUser('otto', User::ROLE_PRINCIPAL);
        $this->assign($this->to, $this->sy, $this->receiver);
        $this->admin = $this->plainUser('boss', User::ROLE_ADMIN);
        $this->learner = $this->learner('100000000001', 'Cruz', 'Juan');
        $this->enroll($this->learner, $this->sy, $this->rizal, $this->admin);
    }

    public function testARequestIsSentApprovedDeclinedAndCancelledThroughTheBus(): void
    {
        // Initialize
        $requested = $this->request($this->receiver);
        $first = $this->pendingId();
        $declined = $this->handle(
            new DeclineTransferCommand($this->id($this->sender), $this->id($this->from), $first, $this->learnerId()),
        );
        $this->request($this->receiver);
        $second = $this->pendingId();
        $cancelled = $this->handle(
            new CancelTransferCommand($this->id($this->receiver), $this->id($this->to), $second, $this->learnerId()),
        );
        $this->request($this->receiver);
        $third = $this->pendingId();

        // Execute
        $approved = $this->handle(new ApproveTransferCommand(
            $this->id($this->sender),
            $this->id($this->from),
            $third,
            $this->learnerId(),
            $this->id($this->to),
        ));

        // Assert
        self::assertSame(['schoolName' => 'Pagsabungan NHS'], $requested->data());
        self::assertSame(['fullName' => 'Cruz, Juan'], $declined->data());
        self::assertSame(['fullName' => 'Cruz, Juan'], $cancelled->data());
        self::assertSame(['fullName' => 'Cruz, Juan', 'schoolName' => 'Mandaue NHS'], $approved->data());
        $this->em->clear();
        $statuses = array_map(
            static fn (TransferRequest $r) => $r->getStatus(),
            $this->em->getRepository(TransferRequest::class)->findBy([], ['id' => 'ASC']),
        );
        self::assertSame([TransferStatus::Declined, TransferStatus::Cancelled, TransferStatus::Approved], $statuses);
        self::assertSame(
            $this->bonifacio->getId(),
            $this->em->getRepository(Enrollment::class)->findOneBy([])?->getSection()->getId(),
        );
    }

    public function testAHeldLockOrADecisionMadeMeanwhileRefusesAndChangesNothing(): void
    {
        // Initialize
        $this->request($this->receiver);
        $transferId = $this->pendingId();
        $approve = new ApproveTransferCommand(
            $this->id($this->sender),
            $this->id($this->from),
            $transferId,
            $this->learnerId(),
            $this->id($this->to),
        );
        $schoolLock = static::getContainer()->get(LockFactory::class)->createLock(
            'learners-school-' . $this->to->getId(),
        );

        // Execute
        self::assertTrue($schoolLock->acquire());
        try {
            $whileBusy = $this->handle($approve);
        } finally {
            $schoolLock->release();
        }
        $this->handle(
            new DeclineTransferCommand(
                $this->id($this->sender),
                $this->id($this->from),
                $transferId,
                $this->learnerId(),
            ),
        );
        $afterDecline = $this->handle($approve);

        // Assert
        self::assertSame(
            ['RESOURCE_BUSY', self::BUSY],
            [$whileBusy->errorCode(), $whileBusy->exception()?->getMessage()],
        );
        self::assertSame(
            [EnrollmentException::class, self::DECIDED],
            [get_debug_type($afterDecline->exception()), $afterDecline->exception()?->getMessage()],
        );
        $this->em->clear();
        self::assertSame(
            $this->rizal->getId(),
            $this->em->getRepository(Enrollment::class)->findOneBy([])?->getSection()->getId(),
        );
    }

    public function testADecisionReadsTheRequestAgainOnceItHoldsTheLock(): void
    {
        // Initialize
        $this->request($this->receiver);
        $transferId = $this->pendingId();
        $actorId = $this->id($this->receiver);
        $page = $this->query(new GetTransferCommand($actorId, $this->id($this->to), $transferId, false));
        self::assertTrue($page->isSuccess());
        // another request approves it after this one's page query but before its lock
        $this->em->getConnection()->executeStatement(
            "UPDATE transfer_request SET status = 'approved' WHERE id = ?",
            [$transferId],
        );

        // Execute
        $cancelled = $this->handle(
            new CancelTransferCommand($actorId, $this->id($this->to), $transferId, $this->learnerId()),
        );

        // Assert
        self::assertSame(
            [EnrollmentException::class, self::DECIDED],
            [get_debug_type($cancelled->exception()), $cancelled->exception()?->getMessage()],
        );
        self::assertSame(
            'approved',
            $this->em->getConnection()->fetchOne('SELECT status FROM transfer_request WHERE id = ?', [$transferId]),
        );
    }

    public function testARequestReadsTheEnrollmentAgainOnceItHoldsTheLock(): void
    {
        // Initialize
        $actorId = $this->id($this->receiver);
        $form = $this->query(new GetTransferRequestFormCommand($actorId, $this->id($this->to), '100000000001'));
        self::assertTrue($form->isSuccess());
        // another request moves the learner into the receiving school meanwhile
        $this->em->getConnection()->executeStatement(
            'UPDATE enrollment SET school_id = ?, section_id = ? WHERE learner_id = ?',
            [$this->id($this->to), $this->bonifacio->getId(), $this->learnerId()],
        );
        $command = new RequestTransferCommand($actorId, $this->id($this->to), '100000000001', $this->learnerId());
        $command->sectionId = (int) $this->bonifacio->getId();

        // Execute
        $sent = $this->handle($command);

        // Assert
        self::assertInstanceOf(TransferLookupChangedException::class, $sent->exception());
        self::assertSame(0, $this->em->getRepository(TransferRequest::class)->count([]));
    }

    public function testRefusesWhatTheAccountOrThePageMayNotReach(): void
    {
        // Initialize
        $this->request($this->receiver);
        $transferId = $this->pendingId();
        $outsider = $this->plainUser('ben', User::ROLE_TEACHER);
        $here = $this->learner('100000000002', 'Reyes', 'Ana');
        $this->enroll($here, $this->sy, $this->bonifacio, $this->admin);
        $requestHere = new RequestTransferCommand(
            $this->id($this->receiver),
            $this->id($this->to),
            '100000000002',
            (int) $here->getId(),
        );
        $requestHere->sectionId = (int) $this->bonifacio->getId();
        $byAdmin = new RequestTransferCommand(
            $this->id($this->admin),
            $this->id($this->to),
            '100000000001',
            $this->learnerId(),
        );
        $byAdmin->sectionId = (int) $this->bonifacio->getId();

        // Execute
        $results = [
            $this->handle($byAdmin),
            $this->handle($requestHere),
            $this->handle(
                new DeclineTransferCommand(
                    $this->id($outsider),
                    $this->id($this->from),
                    $transferId,
                    $this->learnerId(),
                ),
            ),
            $this->query(new GetTransferCommand($this->id($this->sender), $this->id($this->from), $transferId, false)),
            $this->query(new ListTransfersCommand($this->id($outsider), $this->id($this->from), null)),
        ];

        // Assert
        self::assertSame(
            [
                LearnersAccessDeniedException::class,
                TransferLookupChangedException::class,
                LearnersAccessDeniedException::class,
                TransferNotFoundException::class,
                LearnersAccessDeniedException::class,
            ],
            array_map(static fn (Result $r) => get_debug_type($r->exception()), $results),
        );
    }

    public function testTheQueriesGiveThePagesWhatTheyShow(): void
    {
        // Initialize
        $this->request($this->receiver);
        $transferId = $this->pendingId();
        $adviser = $this->plainUser('ana', User::ROLE_TEACHER);
        $this->assign($this->from, $this->sy, $adviser, User::ROLE_TEACHER);
        $this->rizal->setAdviser($adviser);
        $this->from->setPlan($this->plan('Individual'));
        $this->em->flush();

        // Execute
        $outgoing = $this->query(
            new ListTransfersCommand($this->id($this->sender), $this->id($this->from), 'outgoing'),
        );
        $incoming = $this->query(new ListTransfersCommand($this->id($this->receiver), $this->id($this->to), 'bogus'));
        $advisers = $this->query(new ListTransfersCommand($this->id($adviser), $this->id($this->from), null));
        $adviserIncoming = $this->query(
            new ListTransfersCommand($this->id($adviser), $this->id($this->from), 'incoming'),
        );
        $transfer = $this->query(
            new GetTransferCommand($this->id($this->sender), $this->id($this->from), $transferId, true),
        );
        $form = $this->query(
            new GetTransferRequestFormCommand($this->id($this->receiver), $this->id($this->to), '100000000001'),
        );
        $nobody = $this->query(
            new GetTransferRequestFormCommand($this->id($this->receiver), $this->id($this->to), '999999999999'),
        );

        // Assert
        /** @var array{tab: string, adviserOf: mixed, requests: list<array{learner: array{lrn: string}, canDecide: bool, canCancel: bool}>} $out */
        $out = $outgoing->data();
        self::assertSame(['outgoing', null, '100000000001', true, false], [
            $out['tab'],
            $out['adviserOf'],
            $out['requests'][0]['learner']['lrn'],
            $out['requests'][0]['canDecide'],
            $out['requests'][0]['canCancel'],
        ]);
        /** @var array{tab: string, requests: list<array{canDecide: bool, canCancel: bool}>} $in */
        $in = $incoming->data();
        self::assertSame(
            ['incoming', false, true],
            [$in['tab'], $in['requests'][0]['canDecide'], $in['requests'][0]['canCancel']],
        );
        /** @var array{tab: string, adviserOf: array{label: string}} $own */
        $own = $advisers->data();
        self::assertSame(['outgoing', 'Grade 7 – Rizal'], [$own['tab'], $own['adviserOf']['label']]);
        self::assertSame(
            'Only the outgoing requests of your section are yours to see.',
            $adviserIncoming->exception()?->getMessage(),
        );
        self::assertSame(
            ['learnerId' => $this->learnerId(), 'toSchoolId' => $this->id($this->to), 'pending' => true],
            $transfer->data(),
        );
        /** @var array{learnerId: ?int, sections: list<array{label: string}>} $found */
        $found = $form->data();
        self::assertSame([$this->learnerId(), ['Grade 7 – Bonifacio']], [
            $found['learnerId'],
            array_column($found['sections'], 'label'),
        ]);
        /** @var array{learnerId: ?int} $none */
        $none = $nobody->data();
        self::assertNull($none['learnerId']);
    }

    public function testADecidedRequestIsShownToPageUsersAndRefusedToOthers(): void
    {
        // Initialize
        $this->request($this->receiver);
        $transferId = $this->pendingId();
        $this->handle(
            new DeclineTransferCommand(
                $this->id($this->sender),
                $this->id($this->from),
                $transferId,
                $this->learnerId(),
            ),
        );
        $outsider = $this->plainUser('ben', User::ROLE_TEACHER);

        // Execute
        $forPrincipal = $this->query(
            new GetTransferCommand($this->id($this->sender), $this->id($this->from), $transferId, true),
        );
        $forOutsider = $this->query(
            new GetTransferCommand($this->id($outsider), $this->id($this->from), $transferId, true),
        );

        // Assert
        /** @var array{pending: bool} $data */
        $data = $forPrincipal->data();
        self::assertFalse($data['pending']);
        self::assertInstanceOf(LearnersAccessDeniedException::class, $forOutsider->exception());
    }

    private function request(User $by): Result
    {
        $command = new RequestTransferCommand($this->id($by), $this->id($this->to), '100000000001', $this->learnerId());
        $command->sectionId = (int) $this->bonifacio->getId();
        $result = $this->handle($command);
        self::assertTrue($result->isSuccess(), (string) $result->exception()?->getMessage());

        return $result;
    }

    private function pendingId(): int
    {
        $pending = $this->em->getRepository(TransferRequest::class)->findOneBy(['status' => TransferStatus::Pending]);
        self::assertNotNull($pending);

        return (int) $pending->getId();
    }

    private function id(User|School $entity): int
    {
        return (int) $entity->getId();
    }

    private function learnerId(): int
    {
        return (int) $this->learner->getId();
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
