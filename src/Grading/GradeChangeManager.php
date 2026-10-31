<?php

namespace App\Grading;

use App\Entity\Grade;
use App\Entity\GradeChangeRequest;
use App\Entity\GradingPeriod;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Repository\GradeChangeRequestRepository;
use App\Repository\GradeRepository;
use App\Service\ActivityLogger;
use App\Service\SchoolClock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Change requests for grades of a locked period: the teacher sends one, the principal approves it (the grades change
 * at once) or declines it, and the teacher may withdraw it while it waits. Who may do which is a voter's question.
 */
class GradeChangeManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GradeLocks $gradeLocks,
        private readonly GradeRules $rules,
        private readonly GradeRepository $grades,
        private readonly GradeChangeRequestRepository $requests,
        private readonly ActivityLogger $activityLogger,
        private readonly SchoolClock $clock,
    ) {
    }

    /**
     * Sends a change request for the class's grades: a line for each editable learner whose value differs from
     * the grade now. Blank values, unchanged values and ids that aren't editable learners of the sheet are ignored.
     *
     * @param array<int, ?int> $values by enrollment id
     *
     * @throws GradingException
     */
    public function request(TeachingLoad $load, GradingPeriod $period, array $values, string $reason, User $by): GradeChangeRequest
    {
        $section = $load->getSection();
        $subject = $load->getSubject();

        // the class lock keeps it apart from a sheet save and another request; a shared hold on the school's lock
        // keeps the principal from unlocking the period or the class while this one is past its checks
        return $this->gradeLocks->class($section, $subject, fn (): GradeChangeRequest => $this->gradeLocks->school($section->getSchool(), function () use ($load, $section, $subject, $period, $values, $reason, $by): GradeChangeRequest {
            if (!$this->rules->isGradable($load)) {
                throw new GradingException("This class isn't graded here.");
            }
            $status = $this->rules->status($section, $subject, $period);
            if (ClassGradeStatus::Open === $status || ClassGradeStatus::Unlocked === $status) {
                throw new GradingException(\sprintf("%s isn't locked — change the grades on the sheet.", $period->getLabel()));
            }
            if (ClassGradeStatus::Locked !== $status) {
                throw new GradingException($this->rules->refusalToEdit($status, $period) ?? \sprintf("%s isn't open for grading.", $period->getLabel()));
            }
            // one at a time per class, whoever teaches it now
            if (null !== $this->requests->findPendingFor($section, $subject, $period)) {
                throw new GradingException('You already have a pending change request for this class.');
            }
            $reason = trim($reason);
            if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
                throw new GradingException('Write the reason for the change (at least 10 characters).');
            }

            $learners = array_values(array_filter($this->rules->sheet($section), static fn (SheetLearner $learner): bool => $learner->editable));
            foreach ($learners as $learner) {
                $value = $values[(int) $learner->enrollment->getId()] ?? null;
                if (null !== $value && ($value < 60 || $value > 100)) {
                    throw new GradingException('Enter a grade from 60 to 100.');
                }
            }

            $existing = $this->grades->findForEnrollments(GradeRules::enrollmentIds($learners), $subject, $period);
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

            $this->entityManager->persist($request);
            $this->log('Requested', $request, $by);
            $this->entityManager->flush();

            return $request;
        }, shared: true));
    }

    /**
     * Applies the request's grades, as given by the teacher who sent it, and marks it approved. Refused when any of
     * its grades changed since, or one of its learners can no longer be graded: the principal declines it instead.
     *
     * @throws GradingException
     */
    public function approve(GradeChangeRequest $request, User $by): void
    {
        $section = $request->getSection();
        $subject = $request->getSubject();

        // the class lock keeps sheet saves out; the exclusive school lock keeps out a withdraw or decline of this
        // request, and the principal's lock changes, until the grades are in
        $this->gradeLocks->class($section, $subject, fn () => $this->gradeLocks->school($section->getSchool(), function () use ($request, $section, $subject, $by): void {
            $this->refuseUnlessPending($request);

            $period = $request->getGradingPeriod();
            $editable = [];
            foreach ($this->rules->sheet($section) as $learner) {
                if ($learner->editable) {
                    $editable[(int) $learner->enrollment->getId()] = true;
                }
            }
            $lines = $request->getLines()->toArray();
            $ids = array_map(static fn ($line): int => (int) $line->getEnrollment()->getId(), $lines);
            $existing = $this->grades->findForEnrollments($ids, $subject, $period);
            foreach ($lines as $line) {
                $id = (int) $line->getEnrollment()->getId();
                $now = isset($existing[$id]) ? $existing[$id]->getValue() : null;
                if (!isset($editable[$id]) || $now !== $line->getFromValue()) {
                    throw new GradingException('Grades have changed since this request was sent. Decline it so the teacher can send a new one.');
                }
            }

            $at = $this->clock->now();
            $teacher = $request->getRequestedBy();
            foreach ($lines as $line) {
                $grade = $existing[(int) $line->getEnrollment()->getId()] ?? null;
                if (null === $grade) {
                    $this->entityManager->persist(new Grade($line->getEnrollment(), $subject, $period, $line->getToValue(), $teacher, $at));
                } else {
                    $grade->change($line->getToValue(), $teacher, $at);
                }
            }
            $request->approve($by, $at);
            $this->log('Approved', $request, $by);
            $this->entityManager->flush();
        }));
    }

    /** @throws GradingException */
    public function decline(GradeChangeRequest $request, User $by, string $note): void
    {
        $this->gradeLocks->school($request->getSection()->getSchool(), function () use ($request, $by, $note): void {
            $this->refuseUnlessPending($request);
            $note = trim($note);
            if (mb_strlen($note) < 5 || mb_strlen($note) > 1000) {
                throw new GradingException('Write a note for the teacher (at least 5 characters).');
            }

            $request->decline($by, $this->clock->now(), $note);
            $this->log('Declined', $request, $by);
            $this->entityManager->flush();
        });
    }

    /**
     * Takes back a pending request. $by is who is logged; whether they may is the voter's question.
     *
     * @throws GradingException
     */
    public function withdraw(GradeChangeRequest $request, User $by): void
    {
        $this->gradeLocks->school($request->getSection()->getSchool(), function () use ($request, $by): void {
            $this->refuseUnlessPending($request);

            $request->withdraw($this->clock->now());
            $this->log('Withdrew', $request, $by);
            $this->entityManager->flush();
        });
    }

    /** Reads the request's state again, since another request may have decided it since this one loaded it. */
    private function refuseUnlessPending(GradeChangeRequest $request): void
    {
        $this->entityManager->refresh($request);
        if (!$request->isPending()) {
            throw new GradingException('This request has already been decided.');
        }
    }

    private function log(string $verb, GradeChangeRequest $request, User $by): void
    {
        $this->activityLogger->log(
            \sprintf('%s a change of %s grades of %s', $verb, $request->getGradingPeriod()->getLabel(), GradeRecorder::classLabel($request->getSection(), $request->getSubject())),
            $by->getUserIdentifier(),
        );
    }
}
