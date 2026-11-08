<?php

namespace App\Attendance;

use App\Schools\Domain\Service\Calendar\SchoolCalendar;
use App\Entity\LearnerMark;
use App\Entity\RollCall;
use App\Classes\Domain\Model\Section;
use App\Entity\Sf2Submission;
use App\Access\Domain\Model\User;
use App\Repository\RollCallRepository;
use App\Repository\Sf2SubmissionRepository;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Learners\Application\Service\LearnerEnrollment;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Saves roll calls and SF2 submissions. A section's attendance changes one
 * request at a time, under a lock.
 */
class AttendanceRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly AttendanceRules $rules,
        private readonly Sf2Roster $roster,
        private readonly SchoolCalendar $calendar,
        private readonly RollCallRepository $rollCalls,
        private readonly Sf2SubmissionRepository $submissions,
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

        return $this->locked($section, function () use ($section, $date, $marks, $by): RollCall {
            if (null !== $refusal = $this->rules->refusalToTake($section, $date)) {
                throw new AttendanceException($refusal);
            }

            $kept = [];
            foreach ($this->roster->forMonth($section, $date) as $entry) {
                $id = $entry->enrollment->getId();
                if ($entry->counts($date) && null !== ($marks[$id] ?? null)) {
                    $kept[$id] = [$entry->enrollment, $marks[$id]];
                }
            }

            $now = $this->clock->now();
            $rollCall = $this->rollCalls->findOne($section, $date);
            if (null === $rollCall) {
                $rollCall = new RollCall($section, $date, $by, $now);
                $this->entityManager->persist($rollCall);
                $action = 'Took attendance of %s for %s';
            } else {
                $rollCall->touch($now);
                $action = 'Updated attendance of %s for %s';
            }
            $rollCall->replaceMarks($kept);
            $this->activityLogger->log(\sprintf($action, self::label($section), $date->format('Y-m-d')));
            $this->entityManager->flush();

            return $rollCall;
        });
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

        return $this->locked($section, function () use ($section, $month, $by, $confirmMissing): Sf2Submission {
            if ($this->rules->isLocked($section, $month)) {
                throw new AttendanceException(\sprintf('%s is already submitted.', $month->format('F Y')));
            }
            if (!$this->rules->monthHasEnded($section, $month)) {
                throw new AttendanceException(\sprintf('You can submit %s once its last school day has passed.', $month->format('F')));
            }
            $missing = \count($this->rules->missingDays($section, $month));
            if ($missing > 0 && !$confirmMissing) {
                throw new AttendanceException(\sprintf('%d school %s no attendance. Confirm that everyone was present on those days.', $missing, 1 === $missing ? 'day has' : 'days have'));
            }

            $submission = new Sf2Submission($section, $month, $by, $this->clock->now());
            $this->entityManager->persist($submission);
            $this->activityLogger->log(\sprintf('Submitted SF2 of %s for %s', self::label($section), $month->format('F Y')));
            $this->entityManager->flush();

            return $submission;
        });
    }

    /** @throws AttendanceException */
    public function reopen(Section $section, \DateTimeImmutable $month): void
    {
        $this->locked($section, function () use ($section, $month): void {
            $submission = $this->submissions->findOne($section, $month);
            if (null === $submission) {
                throw new AttendanceException(\sprintf('%s is not submitted.', $month->format('F Y')));
            }
            $this->entityManager->remove($submission);
            $this->activityLogger->log(\sprintf('Reopened SF2 of %s for %s', self::label($section), $submission->getMonth()->format('F Y')));
            $this->entityManager->flush();
        });
    }

    /** "Grade 8 – ARTISTIC" */
    public static function label(Section $section): string
    {
        return ltrim(($section->getGradeLevel()?->label() ?? '').' – '.mb_strtoupper($section->getName()), ' –');
    }

    /**
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     */
    private function locked(Section $section, callable $change): mixed
    {
        $lock = $this->lockFactory->createLock('attendance-section-'.$section->getId());
        if (!$lock->acquire()) {
            throw new AttendanceException(LearnerEnrollment::BUSY);
        }

        try {
            return $change();
        } finally {
            $lock->release();
        }
    }
}
