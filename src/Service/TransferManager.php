<?php

namespace App\Service;

use App\Entity\Enrollment;
use App\Entity\Section;
use App\Entity\TransferRequest;
use App\Entity\TransferStatus;
use App\Entity\User;
use App\Repository\TransferRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Requests to move a learner to another school, and the decisions on them. Everything that
 * decides is read again under the learner lock (and the receiving school's lock), because the
 * request or enrollment the caller loaded may be stale. The caller has already checked who may do it.
 */
class TransferManager
{
    private const DECIDED = 'This request was already decided.';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanLimits $planLimits,
        private readonly LearnerLock $lock,
        private readonly TransferRequestRepository $transfers,
        private readonly EnrollmentGuard $guard,
        private readonly ActivityLogger $activityLogger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Asks to move the learner to $to, a section of another school. The learner stays where they are.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function request(Enrollment $from, Section $to, User $by): TransferRequest
    {
        $schoolYear = $this->guard->currentYear();
        $this->guard->assertCurrentSection($to, $schoolYear);

        $loadedSchool = $from->getSchool();
        $loadedSection = $from->getSection();

        return $this->lock->forLearner($from->getLearner(), function () use ($from, $to, $by, $schoolYear, $loadedSchool, $loadedSection): TransferRequest {
            $this->guard->reloadEnrollment($from, $loadedSchool, $loadedSection);
            $this->guard->assertCurrentEnrollment($from, $schoolYear);

            $learner = $from->getLearner();
            $this->guard->assertNoPendingTransfer($learner);
            if ($to->getSchool() === $from->getSchool()) {
                throw new EnrollmentException('Choose a section of another school.');
            }
            // seats are checked when the request is approved, not now
            if (null !== $reason = $this->planLimits->blockedReason($to->getSchool())) {
                throw new PlanLimitException($reason);
            }

            $request = new TransferRequest($from, $to, $by, $this->clock->now());
            $this->entityManager->persist($request);
            $this->activityLogger->log(\sprintf('Requested transfer of %s from %s', $learner->getLrn(), $from->getSchool()->getName()));
            $this->entityManager->flush();

            return $request;
        });
    }

    /**
     * Moves the learner to the requested section if the receiving school and section still have room.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function approve(TransferRequest $request, User $by): void
    {
        $schoolYear = $this->guard->currentYear();

        $this->lock->forLearner($request->getLearner(), fn () => $this->lock->forSchool($request->getToSchool(), function () use ($request, $by, $schoolYear): void {
            $this->reloadPending($request);
            // a pending request always has its enrollment; unenrolling is refused while one waits
            $enrollment = $request->getEnrollment() ?? throw new EnrollmentException('This learner is no longer enrolled.');
            $this->guard->reloadEnrollment($enrollment, $request->getFromSchool(), $request->getFromSection());
            $this->guard->assertCurrentEnrollment($enrollment, $schoolYear);
            $to = $request->getToSection();
            $this->guard->assertCurrentSection($to, $schoolYear);

            // approving writes to both schools' data
            if (null !== $reason = $this->planLimits->blockedReason($request->getFromSchool())) {
                throw new PlanLimitException($reason);
            }
            $this->planLimits->assertCanAddStudent($to->getSchool(), $schoolYear, $to);

            $enrollment->moveTo($to);
            $request->decide(TransferStatus::Approved, $by, $this->clock->now());
            $this->activityLogger->log(\sprintf('Approved transfer of %s to %s', $request->getLearner()->getLrn(), $to->getSchool()->getName()));
            $this->entityManager->flush();
        }));
    }

    /**
     * The school being left says no; nothing moves.
     *
     * @throws EnrollmentException
     */
    public function decline(TransferRequest $request, User $by): void
    {
        $this->close($request, $by, TransferStatus::Declined, fn () => \sprintf('Declined transfer of %s to %s', $request->getLearner()->getLrn(), $request->getToSchool()->getName()));
    }

    /**
     * The receiving school withdraws the request.
     *
     * @throws EnrollmentException
     */
    public function cancel(TransferRequest $request, User $by): void
    {
        $this->close($request, $by, TransferStatus::Cancelled, fn () => \sprintf('Cancelled transfer request for %s', $request->getLearner()->getLrn()));
    }

    /** @param callable(): string $logText */
    private function close(TransferRequest $request, User $by, TransferStatus $status, callable $logText): void
    {
        $this->lock->forLearner($request->getLearner(), function () use ($request, $by, $status, $logText): void {
            $this->reloadPending($request);

            $request->decide($status, $by, $this->clock->now());
            $this->activityLogger->log($logText());
            $this->entityManager->flush();
        });
    }

    /** Re-reads the request as it is now and refuses it when it has been decided meanwhile. */
    private function reloadPending(TransferRequest $request): void
    {
        // refresh() silently keeps the old state of a row that is gone, so look for it first
        if (0 === $this->transfers->count(['id' => $request->getId()])) {
            throw new EnrollmentException(self::DECIDED);
        }
        $this->entityManager->refresh($request);
        if (!$request->isPending()) {
            throw new EnrollmentException(self::DECIDED);
        }
    }
}
