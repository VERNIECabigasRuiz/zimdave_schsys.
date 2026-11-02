<?php

namespace App\Attendance;

use App\Entity\LearnerMark;
use App\Entity\RollCall;
use App\Entity\School;
use App\Entity\SchoolDayOff;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Sf2Submission;
use App\Entity\User;
use App\Repository\RollCallRepository;
use App\Repository\SchoolDayOffRepository;
use App\Repository\Sf2SubmissionRepository;
use App\Service\ActivityLogger;
use App\Service\LearnerLock;
use App\Service\SchoolClock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Saves roll calls, SF2 submissions and days off. A section's attendance changes one
 * request at a time, under a lock.
 */
class AttendanceRecorder
{
    private const MAX_RANGE_DAYS = 62;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly AttendanceRules $rules,
        private readonly Sf2Roster $roster,
        private readonly SchoolCalendar $calendar,
        private readonly RollCallRepository $rollCalls,
        private readonly Sf2SubmissionRepository $submissions,
        private readonly SchoolDayOffRepository $daysOff,
        private readonly ActivityLogger $activityLogger,
        private readonly SchoolClock $clock,
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

    /**
     * Marks every weekday from $from to $to (or $from alone) as a day off, skipping days already off.
     *
     * @return array{added: int, sectionsWithAttendance: int} how many days were added, and how many
     *                                                        sections had taken attendance on them
     *
     * @throws AttendanceException
     */
    public function addDaysOff(School $school, SchoolYear $schoolYear, \DateTimeImmutable $from, ?\DateTimeImmutable $to, string $reason, User $by): array
    {
        $from = $from->setTime(0, 0);
        $to = ($to ?? $from)->setTime(0, 0);
        $start = $schoolYear->getStartDate()?->setTime(0, 0);
        $end = $schoolYear->getEndDate()?->setTime(0, 0);
        if ($to < $from) {
            throw new AttendanceException('The end date must be on or after the start date.');
        }
        if ((null !== $start && $from < $start) || (null !== $end && $to > $end)) {
            throw new AttendanceException(\sprintf('Days off must be inside the school year (%s – %s).', $start?->format('M j, Y'), $end?->format('M j, Y')));
        }
        if ($from->diff($to)->days >= self::MAX_RANGE_DAYS) {
            throw new AttendanceException(\sprintf('Add at most %d days at a time.', self::MAX_RANGE_DAYS));
        }

        $existing = $this->daysOff->findBetween($school, $from, $to);
        $now = $this->clock->now();
        $added = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') <= 5 && !isset($existing[$day->format('Y-m-d')])) {
                $this->entityManager->persist(new SchoolDayOff($school, $schoolYear, $day, $reason, $by, $now));
                $added[] = $day;
            }
        }
        if ([] === $added) {
            return ['added' => 0, 'sectionsWithAttendance' => 0];
        }

        $this->activityLogger->log(\sprintf('Added day off %s%s: %s', $from->format('Y-m-d'), $to > $from ? ' to '.$to->format('Y-m-d') : '', trim($reason)));
        $this->entityManager->flush();

        return ['added' => \count($added), 'sectionsWithAttendance' => $this->rollCalls->countSectionsOn($school, $added)];
    }

    public function removeDayOff(SchoolDayOff $dayOff): void
    {
        $this->activityLogger->log(\sprintf('Removed day off %s (%s)', $dayOff->getDate()->format('Y-m-d'), $dayOff->getReason()));
        $this->entityManager->remove($dayOff);
        $this->entityManager->flush();
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
            throw new AttendanceException(LearnerLock::BUSY);
        }

        try {
            return $change();
        } finally {
            $lock->release();
        }
    }
}
