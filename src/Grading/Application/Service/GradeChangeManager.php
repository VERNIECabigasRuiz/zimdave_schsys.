<?php

declare(strict_types=1);

namespace App\Grading\Application\Service;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\TeachingLoad;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Grading\Domain\Exception\GradingException;
use App\Grading\Domain\Model\ClassGradeStatus;
use App\Grading\Domain\Model\Grade;
use App\Grading\Domain\Model\GradeChangeRequest;
use App\Grading\Domain\Model\Repository\GradeChangeRequestRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;
use App\Schools\Domain\Model\GradingPeriod;

/**
 * Change requests for grades of a locked period: the teacher sends one, the principal approves it (the grades change
 * at once) or declines it, and the teacher may withdraw it while it waits. Callers take the grade locks before
 * reading anything, and save the changes; who may do which is GradePolicy's question.
 */
class GradeChangeManager
{
    public function __construct(
        private readonly GradeRules $rules,
        private readonly GradeRepositoryInterface $grades,
        private readonly GradeChangeRequestRepositoryInterface $requests,
        private readonly ActivityLoggerInterface $activityLogger,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /**
     * Why a change request can't be sent for the class now, as request() would refuse it; null when it can.
     */
    public function refusalToRequest(TeachingLoad $load, GradingPeriod $period): ?string
    {
        if (!$this->rules->isGradable($load)) {
            return "This class isn't graded here.";
        }
        $section = $load->getSection();
        $subject = $load->getSubject();
        $status = $this->rules->status($section, $subject, $period);
        if (ClassGradeStatus::Open === $status || ClassGradeStatus::Unlocked === $status) {
            return \sprintf("%s isn't locked — change the grades on the sheet.", $period->getLabel());
        }
        if (ClassGradeStatus::Locked !== $status) {
            return $this->rules->refusalToEdit($status, $period)
                ?? \sprintf("%s isn't open for grading.", $period->getLabel());
        }
        // one at a time per class, whoever teaches it now
        if (null !== $this->requests->findPendingFor($section, $subject, $period)) {
            return 'You already have a pending change request for this class.';
        }

        return null;
    }

    /**
     * Sends a change request for the class's grades: a line for each editable learner whose value differs from
     * the grade now. Blank values, unchanged values and ids that aren't editable learners of the sheet are ignored.
     *
     * @param array<int, ?int> $values by enrollment id
     *
     * @throws GradingException
     */
    public function request(
        TeachingLoad $load,
        GradingPeriod $period,
        array $values,
        string $reason,
        User $by,
    ): GradeChangeRequest {
        $refusal = $this->refusalToRequest($load, $period);
        if (null !== $refusal) {
            throw new GradingException($refusal);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw new GradingException('Write the reason for the change (at least 10 characters).');
        }

        $section = $load->getSection();
        $subject = $load->getSubject();
        $learners = array_values(array_filter(
            $this->rules->sheet($section),
            static fn (SheetLearner $learner): bool => $learner->editable,
        ));
        foreach ($learners as $learner) {
            $value = $values[(int) $learner->enrollment->getId()] ?? null;
            if (null !== $value && ($value < 60 || $value > 100)) {
                throw new GradingException('Enter a grade from 60 to 100.');
            }
        }

        // read again: the change form may have loaded older copies earlier in the same request
        $existing = $this->grades->findFreshForEnrollments(GradeRules::enrollmentIds($learners), $subject, $period);
        $request = new GradeChangeRequest($section, $subject, $period, $by, $reason, $this->clock->now());
        foreach ($learners as $learner) {
            $id = (int) $learner->enrollment->getId();
            $value = $values[$id] ?? null;
            $from = isset($existing[$id]) ? $existing[$id]->getValue() : null;
            if (null !== $value && $value !== $from) {
                $request->addLine($learner->enrollment, $from, $value);
            }
        }
        if ($request->getLines()->isEmpty()) {
            throw new GradingException('Enter at least one corrected grade.');
        }

        $this->requests->add($request);
        $this->log('Requested', $request, $by);

        return $request;
    }

    /**
     * Applies the request's grades, as given by the teacher who sent it, and marks it approved. Refused when any of
     * its grades changed since, or one of its learners can no longer be graded: the principal declines it instead.
     *
     * @throws GradingException
     */
    public function approve(GradeChangeRequest $request, User $by): void
    {
        $this->refuseUnlessPending($request);
        $section = $request->getSection();
        $subject = $request->getSubject();
        $period = $request->getGradingPeriod();

        $editable = [];
        foreach ($this->rules->sheet($section) as $learner) {
            if ($learner->editable) {
                $editable[(int) $learner->enrollment->getId()] = true;
            }
        }
        $lines = $request->getLines()->toArray();
        $ids = array_values(array_map(static fn ($line): int => (int) $line->getEnrollment()->getId(), $lines));
        $existing = $this->grades->findFreshForEnrollments($ids, $subject, $period);
        foreach ($lines as $line) {
            $id = (int) $line->getEnrollment()->getId();
            $now = isset($existing[$id]) ? $existing[$id]->getValue() : null;
            if (!isset($editable[$id]) || $now !== $line->getFromValue()) {
                throw new GradingException(
                    'Grades have changed since this request was sent. Decline it so the teacher can send a new one.',
                );
            }
        }

        $at = $this->clock->now();
        $teacher = $request->getRequestedBy();
        foreach ($lines as $line) {
            $grade = $existing[(int) $line->getEnrollment()->getId()] ?? null;
            if (null === $grade) {
                $this->grades->add(
                    new Grade($line->getEnrollment(), $subject, $period, $line->getToValue(), $teacher, $at),
                );
            } else {
                $grade->change($line->getToValue(), $teacher, $at);
            }
        }
        $request->approve($by, $at);
        $this->log('Approved', $request, $by);
    }

    /** @throws GradingException */
    public function decline(GradeChangeRequest $request, User $by, string $note): void
    {
        $this->refuseUnlessPending($request);
        $note = trim($note);
        if (mb_strlen($note) < 5 || mb_strlen($note) > 1000) {
            throw new GradingException('Write a note for the teacher (at least 5 characters).');
        }

        $request->decline($by, $this->clock->now(), $note);
        $this->log('Declined', $request, $by);
    }

    /**
     * Takes back a pending request. $by is who is logged; whether they may is GradePolicy's question.
     *
     * @throws GradingException
     */
    public function withdraw(GradeChangeRequest $request, User $by): void
    {
        $this->refuseUnlessPending($request);
        $request->withdraw($this->clock->now());
        $this->log('Withdrew', $request, $by);
    }

    /** Reads the request's state again, since another request may have decided it since this one loaded it. */
    private function refuseUnlessPending(GradeChangeRequest $request): void
    {
        $fresh = $this->requests->findFresh((int) $request->getId());
        if (null === $fresh || !$fresh->isPending()) {
            throw new GradingException('This request has already been decided.');
        }
    }

    private function log(string $verb, GradeChangeRequest $request, User $by): void
    {
        $this->activityLogger->log(
            \sprintf(
                '%s a change of %s grades of %s',
                $verb,
                $request->getGradingPeriod()->getLabel(),
                GradeRecorder::classLabel($request->getSection(), $request->getSubject()),
            ),
            $by->getUserIdentifier(),
        );
    }
}
