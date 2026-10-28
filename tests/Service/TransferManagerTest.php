<?php

namespace App\Tests\Service;

use App\Entity\ActivityLog;
use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\TransferRequest;
use App\Entity\TransferStatus;
use App\Entity\User;
use App\Repository\SchoolYearRepository;
use App\Repository\TransferRequestRepository;
use App\Service\ActivityLogger;
use App\Service\EnrollmentException;
use App\Service\EnrollmentGuard;
use App\Service\LearnerLock;
use App\Service\PlanLimitException;
use App\Service\PlanLimits;
use App\Service\TransferManager;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

class TransferManagerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private TransferManager $service;
    private SchoolYear $sy;
    private School $school;
    private School $other;
    private Section $rizal;
    private Section $mabini;
    private Section $otherSection;
    private User $admin;
    private Enrollment $enrollment;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-16 09:00:00');
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS');
        $this->other = $this->school('303457', 'Mandaue NHS');
        $this->rizal = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Rizal');
        $this->mabini = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Mabini');
        $this->otherSection = $this->newSection($this->other, $this->sy, GradeLevel::Grade7, 'Bonifacio');
        $this->admin = $this->plainUser('boss', User::ROLE_ADMIN);
        $this->enrollment = $this->enroll($this->learner('123456789012', 'Cruz', 'Juan'), $this->sy, $this->rizal, $this->admin);
        $this->service = new TransferManager(
            $this->em,
            $c->get(PlanLimits::class),
            new LearnerLock($c->get(LockFactory::class), $c->get(ManagerRegistry::class)),
            $c->get(TransferRequestRepository::class),
            new EnrollmentGuard($this->em, $c->get(SchoolYearRepository::class), $c->get(TransferRequestRepository::class)),
            $c->get(ActivityLogger::class),
            $c->get(ClockInterface::class),
        );
    }

    /** @return list<string> */
    private function logs(): array
    {
        return array_map(static fn (ActivityLog $l) => $l->getAction(), $this->em->getRepository(ActivityLog::class)->findAll());
    }

    private function refused(string $message, callable $change, string $class = EnrollmentException::class): void
    {
        try {
            $change();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e, $e->getMessage());
            self::assertSame($message, $e->getMessage());

            return;
        }
        self::fail('Expected '.$class.': '.$message);
    }

    public function testRequestLeavesLearnerInPlace(): void
    {
        $request = $this->service->request($this->enrollment, $this->otherSection, $this->admin);

        self::assertNotNull($request->getId());
        self::assertTrue($request->isPending());
        self::assertEquals(new \DateTimeImmutable('2026-10-16 09:00:00'), $request->getCreatedAt());
        self::assertSame($this->otherSection, $request->getToSection());
        self::assertSame($this->school, $this->enrollment->getSchool());
        self::assertSame($this->rizal, $this->enrollment->getSection());
        self::assertContains('Requested transfer of 123456789012 from Pagsabungan NHS', $this->logs());
    }

    public function testSecondPendingRequestRefused(): void
    {
        $this->service->request($this->enrollment, $this->otherSection, $this->admin);

        $this->refused('Cruz, Juan has a transfer request waiting.', fn () => $this->service->request($this->enrollment, $this->otherSection, $this->admin));
        self::assertSame(1, $this->em->getRepository(TransferRequest::class)->count([]));
    }

    public function testPendingRequestOfPastYearDoesNotBlockNewRequest(): void
    {
        $this->service->request($this->enrollment, $this->otherSection, $this->admin);
        $this->sy->setCurrent(false);
        $this->em->flush();
        $next = $this->schoolYear(2027, current: true);
        $rizal = $this->newSection($this->school, $next, GradeLevel::Grade8, 'Rizal');
        $target = $this->newSection($this->other, $next, GradeLevel::Grade8, 'Bonifacio');
        $enrollment = $this->enroll($this->enrollment->getLearner(), $next, $rizal, $this->admin);

        $request = $this->service->request($enrollment, $target, $this->admin);

        self::assertTrue($request->isPending());
    }

    public function testRequestToSameSchoolRefused(): void
    {
        $this->refused('Choose a section of another school.', fn () => $this->service->request($this->enrollment, $this->mabini, $this->admin));
    }

    public function testRequestToPastYearSectionRefused(): void
    {
        $old = $this->schoolYear(2025, current: false);
        $oldSection = $this->newSection($this->other, $old, GradeLevel::Grade7, 'Old');

        $this->refused('Choose a section of the current school year.', fn () => $this->service->request($this->enrollment, $oldSection, $this->admin));
    }

    public function testRequestBlockedPlan(): void
    {
        $this->other->setActive(false);
        $this->em->flush();

        $this->refused('This school is inactive.', fn () => $this->service->request($this->enrollment, $this->otherSection, $this->admin), PlanLimitException::class);
        self::assertSame(0, $this->em->getRepository(TransferRequest::class)->count([]));
    }

    public function testApproveMovesEnrollment(): void
    {
        $request = $this->service->request($this->enrollment, $this->otherSection, $this->admin);
        $approver = $this->plainUser('principal', User::ROLE_PRINCIPAL);

        $this->service->approve($request, $approver);

        self::assertSame($this->other, $this->enrollment->getSchool());
        self::assertSame($this->otherSection, $this->enrollment->getSection());
        self::assertSame(TransferStatus::Approved, $request->getStatus());
        self::assertSame($approver, $request->getDecidedBy());
        self::assertEquals(new \DateTimeImmutable('2026-10-16 09:00:00'), $request->getDecidedAt());
        self::assertContains('Approved transfer of 123456789012 to Mandaue NHS', $this->logs());
    }

    public function testApproveRechecksLimits(): void
    {
        // the receiving school has room for one learner, and the request is sent while it is empty
        $this->other->setPlan($this->limitedPlan('Test One Seat', 1));
        $this->em->flush();
        $request = $this->service->request($this->enrollment, $this->otherSection, $this->admin);
        $this->enroll($this->learner('100000000001', 'Reyes', 'Ben'), $this->sy, $this->otherSection, $this->admin);

        $this->refused(
            'The Test One Seat plan allows 1 student(s) (1 used). Contact the administrator to upgrade.',
            fn () => $this->service->approve($request, $this->admin),
            PlanLimitException::class,
        );
        self::assertTrue($request->isPending());
        self::assertSame($this->school, $this->enrollment->getSchool());
        self::assertSame($this->rizal, $this->enrollment->getSection());
    }

    public function testApproveRefusedWhenSectionFullOnPlanWithoutPrincipal(): void
    {
        $solo = $this->school('300001', 'Solo School', plan: $this->limitedPlan('Test Solo', 1, false)->getName(), seats: 2);
        $luna = $this->newSection($solo, $this->sy, GradeLevel::Grade7, 'Luna');
        $request = $this->service->request($this->enrollment, $luna, $this->admin);
        $this->enroll($this->learner('100000000001', 'Reyes', 'Ben'), $this->sy, $luna, $this->admin);

        $this->refused('Grade 7 – Luna is full (1 learner).', fn () => $this->service->approve($request, $this->admin), PlanLimitException::class);
        self::assertTrue($request->isPending());
        self::assertSame($this->rizal, $this->enrollment->getSection());
    }

    public function testApproveRefusedWhenReceivingSchoolBlocked(): void
    {
        $request = $this->service->request($this->enrollment, $this->otherSection, $this->admin);
        $this->other->setActive(false);
        $this->em->flush();

        $this->refused('This school is inactive.', fn () => $this->service->approve($request, $this->admin), PlanLimitException::class);
        self::assertTrue($request->isPending());
        self::assertSame($this->school, $this->enrollment->getSchool());
    }

    public function testRequestRefusedWhenEnrollmentIsStale(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE enrollment SET section_id = ? WHERE id = ?', [$this->mabini->getId(), $this->enrollment->getId()]);

        $this->refused('This learner was moved. Reload the page and try again.', fn () => $this->service->request($this->enrollment, $this->otherSection, $this->admin));
        self::assertSame(0, $this->em->getRepository(TransferRequest::class)->count([]));
    }

    public function testDecidedRequestsDoNotBlockDeletingTheirSection(): void
    {
        $request = $this->service->request($this->enrollment, $this->otherSection, $this->admin);
        $this->service->decline($request, $this->admin);

        $this->em->remove($this->otherSection);
        $this->em->flush();

        self::assertSame(0, $this->em->getRepository(TransferRequest::class)->count([]));
    }

    public function testApproveRefusedWhenSendingSchoolBlocked(): void
    {
        $request = $this->service->request($this->enrollment, $this->otherSection, $this->admin);
        $this->school->setActive(false);
        $this->em->flush();

        $this->refused('This school is inactive.', fn () => $this->service->approve($request, $this->admin), PlanLimitException::class);
        self::assertTrue($request->isPending());
    }

    public function testApproveRefusedWhenLearnerWasMoved(): void
    {
        $request = $this->service->request($this->enrollment, $this->otherSection, $this->admin);
        // moved behind our back by another request
        $this->em->getConnection()->executeStatement('UPDATE enrollment SET section_id = ? WHERE id = ?', [$this->mabini->getId(), $this->enrollment->getId()]);

        $this->refused('This learner was moved. Reload the page and try again.', fn () => $this->service->approve($request, $this->admin));
        self::assertTrue($request->isPending());
    }

    public function testApproveRefusedWhenAlreadyDecidedElsewhere(): void
    {
        $request = $this->service->request($this->enrollment, $this->otherSection, $this->admin);
        $this->em->getConnection()->executeStatement("UPDATE transfer_request SET status = 'declined' WHERE id = ?", [$request->getId()]);

        $this->refused('This request was already decided.', fn () => $this->service->approve($request, $this->admin));
        self::assertSame($this->school, $this->enrollment->getSchool());
    }

    public function testDecline(): void
    {
        $request = $this->service->request($this->enrollment, $this->otherSection, $this->admin);

        $this->service->decline($request, $this->admin);

        self::assertSame(TransferStatus::Declined, $request->getStatus());
        self::assertSame($this->admin, $request->getDecidedBy());
        self::assertSame($this->school, $this->enrollment->getSchool());
        self::assertContains('Declined transfer of 123456789012 to Mandaue NHS', $this->logs());
    }

    public function testCancel(): void
    {
        $request = $this->service->request($this->enrollment, $this->otherSection, $this->admin);

        $this->service->cancel($request, $this->admin);

        self::assertSame(TransferStatus::Cancelled, $request->getStatus());
        self::assertSame($this->school, $this->enrollment->getSchool());
        self::assertContains('Cancelled transfer request for 123456789012', $this->logs());
    }

    public function testDecidedRequestCannotBeDecidedAgain(): void
    {
        $request = $this->service->request($this->enrollment, $this->otherSection, $this->admin);
        $this->service->cancel($request, $this->admin);

        $message = 'This request was already decided.';
        $this->refused($message, fn () => $this->service->approve($request, $this->admin));
        $this->refused($message, fn () => $this->service->decline($request, $this->admin));
        $this->refused($message, fn () => $this->service->cancel($request, $this->admin));
        self::assertSame($this->school, $this->enrollment->getSchool());
    }
}
