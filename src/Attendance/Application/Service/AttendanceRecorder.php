<?php

declare(strict_types=1);

namespace App\Attendance\Application\Service;

use App\Access\Domain\Model\User;
use App\Attendance\Domain\Exception\AttendanceException;
use App\Attendance\Domain\Model\LearnerMark;
use App\Attendance\Domain\Model\Repository\RollCallRepositoryInterface;
use App\Attendance\Domain\Model\Repository\Sf2SubmissionRepositoryInterface;
use App\Attendance\Domain\Model\RollCall;
use App\Attendance\Domain\Model\Sf2Submission;
use App\Classes\Domain\Model\Section;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Learners\Application\Api\SectionRosterInterface;

/**
 * Checks and applies roll calls and SF2 submissions. Callers take the section's attendance lock
 * before reading anything, and save the changes.
 */
class AttendanceRecorder
{
    public const LOCK = 'attendance-section-';
    public const BUSY = 'Another change is being saved. Please try again.';

    public function __construct(
        private readonly AttendanceRules $rules,
        private readonly SectionRosterInterface $roster,
        private readonly RollCallRepositoryInterface $rollCalls,
        private readonly Sf2SubmissionRepositoryInterface $submissions,
        private readonly ActivityLoggerInterface $activityLogger,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /**
     * Records the day's roll call, replacing an earlier one. Learners without a mark are present;
     * marks for learners who don't count that day are ignored.
     *
     * @param array<int, ?LearnerMark> $marks keyed by enrollment id
     *
     * @throws AttendanceException
     */
    public function takeRollCall(Section $section, \DateTimeImmutable $date, array $marks, User $by): RollCall
    {
        $date = $date->setTime(0, 0);
        if (null !== $refusal = $this->rules->refusalToTake($section, $date)) {
            throw new AttendanceException($refusal);
        }

        $kept = [];
        foreach ($this->roster->forMonth($section, $date) as $entry) {
            $id = (int) $entry->enrollment->getId();
            if ($entry->counts($date) && null !== ($marks[$id] ?? null)) {
                $kept[$id] = [$entry->enrollment, $marks[$id]];
            }
        }

        $now = $this->clock->now();
        // read again: the page query may have loaded an older copy earlier in the same request
        $rollCall = $this->rollCalls->findFresh($section, $date);
        if (null === $rollCall) {
            $rollCall = new RollCall($section, $date, $by, $now);
            $this->rollCalls->add($rollCall);
            $action = 'Took attendance of %s for %s';
        } else {
            $rollCall->touch($now);
            $action = 'Updated attendance of %s for %s';
        }
        $rollCall->replaceMarks($kept);
        $this->activityLogger->log(\sprintf($action, $section->getFormLabel(), $date->format('Y-m-d')));

        return $rollCall;
    }

    /**
     * Hands the month's SF2 to the principal, which locks the month.
     *
     * @param bool $confirmMissing the adviser confirmed that everyone was present on school days without a roll call
     *
     * @throws AttendanceException
     */
    public function submit(Section $section, \DateTimeImmutable $month, User $by, bool $confirmMissing): Sf2Submission
    {
        $month = $month->modify('first day of this month')->setTime(0, 0);
        if ($this->rules->isLocked($section, $month)) {
            throw new AttendanceException(\sprintf('%s is already submitted.', $month->format('F Y')));
        }
        if (!$this->rules->monthHasEnded($section, $month)) {
            throw new AttendanceException(
                \sprintf('You can submit %s once its last school day has passed.', $month->format('F')),
            );
        }
        $missing = \count($this->rules->missingDays($section, $month));
        if ($missing > 0 && !$confirmMissing) {
            throw new AttendanceException(\sprintf(
                '%d school %s no attendance. Confirm that everyone was present on those days.',
                $missing,
                1 === $missing ? 'day has' : 'days have',
            ));
        }

        $submission = new Sf2Submission($section, $month, $by, $this->clock->now());
        $this->submissions->add($submission);
        $this->activityLogger->log(
            \sprintf('Submitted SF2 of %s for %s', $section->getFormLabel(), $month->format('F Y')),
        );

        return $submission;
    }

    /** @throws AttendanceException */
    public function reopen(Section $section, \DateTimeImmutable $month): void
    {
        $submission = $this->submissions->findFresh($section, $month);
        if (null === $submission) {
            throw new AttendanceException(\sprintf('%s is not submitted.', $month->format('F Y')));
        }
        $this->submissions->remove($submission);
        $this->activityLogger->log(\sprintf(
            'Reopened SF2 of %s for %s',
            $section->getFormLabel(),
            $submission->getMonth()->format('F Y'),
        ));
    }
}
