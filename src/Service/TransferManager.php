<?php

namespace App\Service;

use App\Entity\Enrollment;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\TransferRequest;
use App\Entity\TransferStatus;
use App\Entity\User;
use App\Repository\SchoolYearRepository;
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
    private const MOVED = 'This learner was moved. Reload the page and try again.';
    private const DECIDED = 'This request was already decided.';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanLimits $planLimits,
        private readonly LearnerLock $lock,
        private readonly TransferRequestRepository $transfers,
        private readonly SchoolYearRepository $schoolYears,
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
        $schoolYear = $this->currentYear();
        $this->assertCurrentSection($to, $schoolYear);

        $loadedSchool = $from->getSchool();
        $loadedSection = $from->getSection();

        return $this->lock->forLearner($from->getLearner(), function () use ($from, $to, $by, $schoolYear, $loadedSchool, $loadedSection): TransferRequest {
            $this->reload($from);
            if ($from->getSchool() !== $loadedSchool || $from->getSection() !== $loadedSection) {
                throw new EnrollmentException(self::MOVED);
            }
            $this->assertCurrentEnrollment($from, $schoolYear);

            $learner = $from->getLearner();
            if (null !== $this->transfers->findPending($learner)) {
                throw new EnrollmentException(\sprintf('%s has a transfer request waiting.', $learner->getUser()->getFullName()));
            }
            if ($to->getSchool() === $from->getSchool()) {
                throw new EnrollmentException('Choose a section of another school.');
            }
            // seats are checked when the request is approved, not now
            if (null !== $reason = $this->planLimits->blockedReason($to->getSchool())) {
                throw new PlanLimitException($reason);
            }

            $request = new TransferRequest($from, $to, $by);
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
        $schoolYear = $this->currentYear();

        $this->lock->forLearner($request->getLearner(), fn () => $this->lock->forSchool($request->getToSchool(), function () use ($request, $by, $schoolYear): void {
            $this->reloadPending($request);
            $enrollment = $request->getEnrollment();
            $this->reload($enrollment);
            if ($enrollment->getSchool() !== $request->getFromSchool() || $enrollment->getSection() !== $request->getFromSection()) {
                throw new EnrollmentException(self::MOVED);
            }
            $this->assertCurrentEnrollment($enrollment, $schoolYear);
            $to = $request->getToSection();
            $this->assertCurrentSection($to, $schoolYear);

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

    private function reload(Enrollment $enrollment): void
    {
        if (0 === $this->entityManager->getRepository(Enrollment::class)->count(['id' => $enrollment->getId()])) {
            throw new EnrollmentException('This learner is no longer enrolled.');
        }
        $this->entityManager->refresh($enrollment);
    }

    private function currentYear(): SchoolYear
    {
        return $this->schoolYears->findCurrent() ?? throw new EnrollmentException('There is no current school year.');
    }

    private function assertCurrentSection(Section $section, SchoolYear $schoolYear): void
    {
        if ($section->getSchoolYear()->getId() !== $schoolYear->getId()) {
            throw new EnrollmentException('Choose a section of the current school year.');
        }
    }

    private function assertCurrentEnrollment(Enrollment $enrollment, SchoolYear $schoolYear): void
    {
        if ($enrollment->getSchoolYear()->getId() !== $schoolYear->getId()) {
            throw new EnrollmentException('Past school years cannot be changed.');
        }
    }
}
