<?php

namespace App\Grading;

use App\Attendance\AttendanceRecorder;
use App\Entity\Grade;
use App\Entity\GradeLock;
use App\Entity\GradeOpening;
use App\Entity\GradeUnlock;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Repository\GradeLockRepository;
use App\Repository\GradeOpeningRepository;
use App\Repository\GradeRepository;
use App\Repository\GradeUnlockRepository;
use App\Service\ActivityLogger;
use App\Service\SchoolClock;
use Doctrine\ORM\EntityManagerInterface;

/** Saves grade sheets and applies the principal's locks. A class's grades, and a school's locks, change one request at a time; a save and a lock never overlap. */
class GradeRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GradeLocks $gradeLocks,
        private readonly GradeRules $rules,
        private readonly GradeRepository $grades,
        private readonly GradeLockRepository $locks,
        private readonly GradeUnlockRepository $unlocks,
        private readonly GradeOpeningRepository $openings,
        private readonly ActivityLogger $activityLogger,
        private readonly SchoolClock $clock,
    ) {
    }

    /**
     * Saves the grades of a class for one period: a value sets the grade, null removes it. Ids that aren't
     * editable learners of the class's sheet are ignored.
     *
     * @param array<int, ?int> $values by enrollment id
     *
     * @return int learners of the class with a grade after the save
     *
     * @throws GradingException
     */
    public function saveSheet(TeachingLoad $load, GradingPeriod $period, array $values, User $by): int
    {
        $section = $load->getSection();
        $subject = $load->getSubject();

        // the class's own lock keeps two saves of the class apart; a shared hold on the school's grade lock keeps
        // the principal from locking the period, or relocking the class, while this save is past its check
        return $this->gradeLocks->class($section, $subject, fn (): int => $this->gradeLocks->school($section->getSchool(), function () use ($load, $section, $subject, $period, $values, $by): int {
            if (!$this->rules->isGradable($load)) {
                throw new GradingException("This class isn't graded here.");
            }
            // a principal can lock between the teacher opening the sheet and saving it
            if (null !== $refusal = $this->rules->refusalToEdit($this->rules->status($section, $subject, $period), $period)) {
                throw new GradingException($refusal);
            }

            $learners = $this->rules->sheet($section);
            // every value is checked before any grade changes, so a refusal leaves nothing half-applied
            foreach ($learners as $learner) {
                $value = $learner->editable ? ($values[(int) $learner->enrollment->getId()] ?? null) : null;
                if (null !== $value && ($value < 60 || $value > 100)) {
                    throw new GradingException('Enter a grade from 60 to 100.');
                }
            }

            $now = $this->clock->now();
            // by enrollment: a learner who transferred out has moved section but keeps the grades given here
            $existing = $this->grades->findForEnrollments(GradeRules::enrollmentIds($learners), $subject, $period);
            $graded = 0;
            foreach ($learners as $learner) {
                $id = (int) $learner->enrollment->getId();
                $grade = $existing[$id] ?? null;
                if ($learner->editable && \array_key_exists($id, $values)) {
                    $value = $values[$id];
                    if (null === $value) {
                        if (null !== $grade) {
                            $this->entityManager->remove($grade);
                            $grade = null;
                        }
                    } elseif (null === $grade) {
                        $grade = new Grade($learner->enrollment, $subject, $period, $value, $by, $now);
                        $this->entityManager->persist($grade);
                    } elseif ($grade->getValue() !== $value) {
                        $grade->change($value, $by, $now);
                    }
                }
                if ($learner->editable && null !== $grade) {
                    ++$graded;
                }
            }

            $this->activityLogger->log(\sprintf('Saved %s grades of %s', $period->getLabel(), self::classLabel($section, $subject)));
            $this->entityManager->flush();

            return $graded;
        }, shared: true));
    }

    /**
     * Opens the period for grading at the school. A locked period must be unlocked first.
     *
     * @throws GradingException
     */
    public function openPeriod(School $school, GradingPeriod $period, User $by): void
    {
        $this->gradeLocks->school($school, function () use ($school, $period, $by): void {
            $this->refuseWhenLocked($school, $period);
            if (null !== $this->openings->findFor($school, $period)) {
                throw new GradingException(\sprintf('%s is already open.', $period->getLabel()));
            }

            $this->entityManager->persist(new GradeOpening($school, $period, $by, $this->clock->now()));
            $this->activityLogger->log(\sprintf('Opened %s grading%s', $period->getLabel(), $this->atSchool($school, $by)));
            $this->entityManager->flush();
        });
    }

    /** @throws GradingException */
    public function closePeriod(School $school, GradingPeriod $period, User $by): void
    {
        $this->gradeLocks->school($school, function () use ($school, $period, $by): void {
            $this->refuseWhenLocked($school, $period);
            $opening = $this->openings->findFor($school, $period);
            if (null === $opening) {
                throw new GradingException(\sprintf('%s is already closed.', $period->getLabel()));
            }

            $this->entityManager->remove($opening);
            $this->activityLogger->log(\sprintf('Closed %s grading%s', $period->getLabel(), $this->atSchool($school, $by)));
            $this->entityManager->flush();
        });
    }

    private function refuseWhenLocked(School $school, GradingPeriod $period): void
    {
        if (null !== $this->locks->findFor($school, $period)) {
            throw new GradingException(\sprintf('%s is locked. Unlock it first.', $period->getLabel()));
        }
    }

    /** An administrator works across schools, so their log entries say which. */
    private function atSchool(School $school, User $by): string
    {
        return \in_array(User::ROLE_ADMIN, $by->getRoles(), true) ? ' at '.$school->getName() : '';
    }

    /** @throws GradingException */
    public function lockPeriod(School $school, GradingPeriod $period, User $by): void
    {
        $this->gradeLocks->school($school, function () use ($school, $period, $by): void {
            if (null !== $this->locks->findFor($school, $period)) {
                throw new GradingException(\sprintf('%s is already locked.', $period->getLabel()));
            }
            if (!$this->rules->canLock($school, $period)) {
                throw new GradingException(\sprintf("%s hasn't been opened for grading yet.", $period->getLabel()));
            }

            $this->unlocks->deleteForSchool($school, $period);
            $this->entityManager->persist(new GradeLock($school, $period, $by, $this->clock->now()));
            $this->activityLogger->log(\sprintf('Locked %s grades', $period->getLabel()));
            $this->entityManager->flush();
        });
    }

    /** @throws GradingException */
    public function unlockPeriod(School $school, GradingPeriod $period): void
    {
        $this->gradeLocks->school($school, function () use ($school, $period): void {
            $lock = $this->locks->findFor($school, $period);
            if (null === $lock) {
                throw new GradingException(\sprintf('%s is not locked.', $period->getLabel()));
            }

            $this->unlocks->deleteForSchool($school, $period);
            $this->entityManager->remove($lock);
            $this->activityLogger->log(\sprintf('Unlocked %s grades', $period->getLabel()));
            $this->entityManager->flush();
        });
    }

    /**
     * Lets the class's teacher correct grades although the period is locked.
     *
     * @throws GradingException
     */
    public function unlockClass(Section $section, Subject $subject, GradingPeriod $period, User $by): void
    {
        $this->gradeLocks->school($section->getSchool(), function () use ($section, $subject, $period, $by): void {
            if (null === $this->locks->findFor($section->getSchool(), $period)) {
                throw new GradingException(\sprintf('%s is not locked.', $period->getLabel()));
            }
            if (null !== $this->unlocks->findFor($section, $subject, $period)) {
                throw new GradingException('This class is already unlocked.');
            }

            $this->entityManager->persist(new GradeUnlock($section, $subject, $period, $by, $this->clock->now()));
            $this->activityLogger->log(\sprintf('Unlocked %s grades of %s', $period->getLabel(), self::classLabel($section, $subject)));
            $this->entityManager->flush();
        });
    }

    /** @throws GradingException */
    public function lockClass(Section $section, Subject $subject, GradingPeriod $period): void
    {
        $this->gradeLocks->school($section->getSchool(), function () use ($section, $subject, $period): void {
            $unlock = $this->unlocks->findFor($section, $subject, $period);
            if (null === $unlock) {
                throw new GradingException('This class is not unlocked.');
            }

            $this->entityManager->remove($unlock);
            $this->activityLogger->log(\sprintf('Locked %s grades of %s again', $period->getLabel(), self::classLabel($section, $subject)));
            $this->entityManager->flush();
        });
    }

    /** "Mathematics – Grade 8 – HONEST" */
    public static function classLabel(Section $section, Subject $subject): string
    {
        return $subject->getName().' – '.AttendanceRecorder::label($section);
    }
}
