<?php

declare(strict_types=1);

namespace App\Learners\Application\Service;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Learners\Domain\Exception\EnrollmentException;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Learners\Domain\Model\Repository\TransferRequestRepositoryInterface;
use App\Learners\Domain\Model\TransferRequest;
use App\Learners\Domain\Model\TransferStatus;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\PlanLimitException;

/**
 * Requests to move a learner to another school, and the decisions on them. Callers take the learner's lock
 * (and, to approve, the receiving school's) before reading anything, and save the changes. The caller has
 * already checked who may do it.
 */
class TransferRequests
{
    public const DECIDED = 'This request was already decided.';

    public function __construct(
        private readonly EnrollmentRules $rules,
        private readonly TransferRequestRepositoryInterface $requests,
        private readonly EnrollmentRepositoryInterface $enrollments,
        private readonly SchoolDirectoryInterface $schools,
        private readonly ActivityLoggerInterface $activityLogger,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /**
     * The pending request of $learnerId with this id.
     *
     * @throws EnrollmentException when it is gone, decided or about another learner
     */
    public function pending(int $transferId, int $learnerId): TransferRequest
    {
        // read again: the page query may have loaded an older copy before the lock was taken
        $request = $this->requests->findFresh($transferId);
        if (null === $request || !$request->isPending() || $request->getLearner()->getId() !== $learnerId) {
            throw new EnrollmentException(self::DECIDED);
        }

        return $request;
    }

    /**
     * Asks to move the learner to $to, a section of another school. The learner stays where they are.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function request(Enrollment $from, Section $to, User $by): TransferRequest
    {
        $schoolYear = $this->rules->currentYear();
        $this->rules->assertCurrentSection($to, $schoolYear);
        $this->rules->assertCurrentEnrollment($from, $schoolYear);

        $learner = $from->getLearner();
        $this->rules->assertNoPendingTransfer($learner);
        if ($to->getSchool() === $from->getSchool()) {
            throw new EnrollmentException('Choose a section of another school.');
        }
        // seats are checked when the request is approved, not now
        if (null !== $reason = $this->schools->blockedReason($to->getSchool())) {
            throw new PlanLimitException($reason);
        }

        $request = new TransferRequest($from, $to, $by, $this->now());
        $this->requests->add($request);
        $this->activityLogger->log(
            \sprintf('Requested transfer of %s from %s', $learner->getLrn(), $from->getSchool()->getName()),
        );

        return $request;
    }

    /**
     * Moves the learner to the requested section if the receiving school and section still have room.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function approve(TransferRequest $request, User $by): void
    {
        $schoolYear = $this->rules->currentYear();
        // a pending request always has its enrollment; unenrolling is refused while one waits
        $enrollmentId = $request->getEnrollment()?->getId();
        $enrollment = (null !== $enrollmentId ? $this->enrollments->findFresh($enrollmentId) : null)
            ?? throw new EnrollmentException('This learner is no longer enrolled.');
        $this->rules->assertCurrentEnrollment($enrollment, $schoolYear);
        $to = $request->getToSection();
        $this->rules->assertCurrentSection($to, $schoolYear);

        // approving writes to both schools' data
        if (null !== $reason = $this->schools->blockedReason($request->getFromSchool())) {
            throw new PlanLimitException($reason);
        }
        $refusal = $this->schools->studentRefusal($to->getSchool(), $schoolYear, (int) $to->getId(), $to->getLabel());
        if (null !== $refusal) {
            throw new PlanLimitException($refusal);
        }

        $enrollment->moveTo($to);
        $request->decide(TransferStatus::Approved, $by, $this->now());
        $this->activityLogger->log(\sprintf(
            'Approved transfer of %s to %s',
            $request->getLearner()->getLrn(),
            $to->getSchool()->getName(),
        ));
    }

    /** The school being left says no; nothing moves. */
    public function decline(TransferRequest $request, User $by): void
    {
        $request->decide(TransferStatus::Declined, $by, $this->now());
        $this->activityLogger->log(\sprintf(
            'Declined transfer of %s to %s',
            $request->getLearner()->getLrn(),
            $request->getToSchool()->getName(),
        ));
    }

    /** The receiving school withdraws the request. */
    public function cancel(TransferRequest $request, User $by): void
    {
        $request->decide(TransferStatus::Cancelled, $by, $this->now());
        $this->activityLogger->log(\sprintf('Cancelled transfer request for %s', $request->getLearner()->getLrn()));
    }

    /** Now in the server's time zone, the one these records have always been stored in. */
    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
