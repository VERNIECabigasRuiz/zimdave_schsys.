<?php

declare(strict_types=1);

namespace App\Grading\Application\Service;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Grading\Domain\Exception\GradingException;
use App\Grading\Domain\Model\Grade;
use App\Grading\Domain\Model\GradeLock;
use App\Grading\Domain\Model\GradeOpening;
use App\Grading\Domain\Model\GradeUnlock;
use App\Grading\Domain\Model\Repository\GradeLockRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeOpeningRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeUnlockRepositoryInterface;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;

/**
 * Checks and applies grade sheets and the principal's openings and locks. Callers take the grade locks before
 * reading anything, and save the changes.
 */
class GradeRecorder
{
    /** A class's own lock, held while its grades change. */
    public const CLASS_LOCK = 'grades-';

    /** The school's grade lock: exclusive while the principal changes locks or openings, shared during a save. */
    public const SCHOOL_LOCK = 'grade-lock-';

    public const BUSY = 'Another change is being saved. Please try again.';

    public function __construct(
        private readonly GradeRules $rules,
        private readonly GradeRepositoryInterface $grades,
        private readonly GradeLockRepositoryInterface $locks,
        private readonly GradeUnlockRepositoryInterface $unlocks,
        private readonly GradeOpeningRepositoryInterface $openings,
        private readonly ActivityLoggerInterface $activityLogger,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    public static function classLock(int $sectionId, int $subjectId): string
    {
        return \sprintf('%s%d-%d', self::CLASS_LOCK, $sectionId, $subjectId);
    }

    public static function schoolLock(int $schoolId): string
    {
        return self::SCHOOL_LOCK . $schoolId;
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
        if (!$this->rules->isGradable($load)) {
            throw new GradingException("This class isn't graded here.");
        }
        // a principal can lock between the teacher opening the sheet and saving it
        $refusal = $this->rules->refusalToEdit($this->rules->status($section, $subject, $period), $period);
        if (null !== $refusal) {
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
        // by enrollment: a learner who transferred out has moved section but keeps the grades given here;
        // read again: the sheet page may have loaded older copies earlier in the same request
        $existing = $this->grades->findFreshForEnrollments(GradeRules::enrollmentIds($learners), $subject, $period);
        $graded = 0;
        foreach ($learners as $learner) {
            $id = (int) $learner->enrollment->getId();
            $grade = $existing[$id] ?? null;
            if ($learner->editable && \array_key_exists($id, $values)) {
                $grade = $this->apply($grade, $values[$id], $learner, $subject, $period, $by, $now);
            }
            if ($learner->editable && null !== $grade) {
                ++$graded;
            }
        }

        $this->activityLogger->log(
            \sprintf('Saved %s grades of %s', $period->getLabel(), self::classLabel($section, $subject)),
        );

        return $graded;
    }

    /**
     * Opens the period for grading at the school. A locked period must be unlocked first.
     *
     * @throws GradingException
     */
    public function openPeriod(School $school, GradingPeriod $period, User $by): void
    {
        $this->refuseWhenLocked($school, $period);
        if (null !== $this->openings->findFor($school, $period)) {
            throw new GradingException(\sprintf('%s is already open.', $period->getLabel()));
        }

        $this->openings->add(new GradeOpening($school, $period, $by, $this->clock->now()));
        $this->activityLogger->log(
            \sprintf('Opened %s grading%s', $period->getLabel(), $this->atSchool($school, $by)),
        );
    }

    /** @throws GradingException */
    public function closePeriod(School $school, GradingPeriod $period, User $by): void
    {
        $this->refuseWhenLocked($school, $period);
        $opening = $this->openings->findFor($school, $period);
        if (null === $opening) {
            throw new GradingException(\sprintf('%s is already closed.', $period->getLabel()));
        }

        $this->openings->remove($opening);
        $this->activityLogger->log(
            \sprintf('Closed %s grading%s', $period->getLabel(), $this->atSchool($school, $by)),
        );
    }

    /** @throws GradingException */
    public function lockPeriod(School $school, GradingPeriod $period, User $by): void
    {
        if (null !== $this->locks->findFor($school, $period)) {
            throw new GradingException(\sprintf('%s is already locked.', $period->getLabel()));
        }
        if (!$this->rules->canLock($school, $period)) {
            throw new GradingException(\sprintf("%s hasn't been opened for grading yet.", $period->getLabel()));
        }

        $this->unlocks->deleteForSchool($school, $period);
        $this->locks->add(new GradeLock($school, $period, $by, $this->clock->now()));
        $this->activityLogger->log(\sprintf('Locked %s grades', $period->getLabel()));
    }

    /** @throws GradingException */
    public function unlockPeriod(School $school, GradingPeriod $period): void
    {
        $lock = $this->locks->findFor($school, $period);
        if (null === $lock) {
            throw new GradingException(\sprintf('%s is not locked.', $period->getLabel()));
        }

        $this->unlocks->deleteForSchool($school, $period);
        $this->locks->remove($lock);
        $this->activityLogger->log(\sprintf('Unlocked %s grades', $period->getLabel()));
    }

    /**
     * Lets the class's teacher correct grades although the period is locked.
     *
     * @throws GradingException
     */
    public function unlockClass(Section $section, Subject $subject, GradingPeriod $period, User $by): void
    {
        if (null === $this->locks->findFor($section->getSchool(), $period)) {
            throw new GradingException(\sprintf('%s is not locked.', $period->getLabel()));
        }
        if (null !== $this->unlocks->findFor($section, $subject, $period)) {
            throw new GradingException('This class is already unlocked.');
        }

        $this->unlocks->add(new GradeUnlock($section, $subject, $period, $by, $this->clock->now()));
        $this->activityLogger->log(
            \sprintf('Unlocked %s grades of %s', $period->getLabel(), self::classLabel($section, $subject)),
        );
    }

    /** @throws GradingException */
    public function lockClass(Section $section, Subject $subject, GradingPeriod $period): void
    {
        $unlock = $this->unlocks->findFor($section, $subject, $period);
        if (null === $unlock) {
            throw new GradingException('This class is not unlocked.');
        }

        $this->unlocks->remove($unlock);
        $this->activityLogger->log(
            \sprintf('Locked %s grades of %s again', $period->getLabel(), self::classLabel($section, $subject)),
        );
    }

    /** "Mathematics – Grade 8 – HONEST" */
    public static function classLabel(Section $section, Subject $subject): string
    {
        return $subject->getName() . ' – ' . $section->getFormLabel();
    }

    /** The learner's grade after setting it to $value (null removes it). */
    private function apply(
        ?Grade $grade,
        ?int $value,
        SheetLearner $learner,
        Subject $subject,
        GradingPeriod $period,
        User $by,
        \DateTimeImmutable $now,
    ): ?Grade {
        if (null === $value) {
            if (null !== $grade) {
                $this->grades->remove($grade);
            }

            return null;
        }
        if (null === $grade) {
            $grade = new Grade($learner->enrollment, $subject, $period, $value, $by, $now);
            $this->grades->add($grade);
        } elseif ($grade->getValue() !== $value) {
            $grade->change($value, $by, $now);
        }

        return $grade;
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
        return \in_array(User::ROLE_ADMIN, $by->getRoles(), true) ? ' at ' . $school->getName() : '';
    }
}
