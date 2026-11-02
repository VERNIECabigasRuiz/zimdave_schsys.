<?php

namespace App\Service;

use App\Entity\Learner;
use App\Entity\School;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Lock\LockFactory;

/**
 * Runs one change to a school's learners (or to one learner) at a time, so two requests
 * can't both pass the limit check and then both save. Always take the learner lock first,
 * then the school lock.
 */
class LearnerLock
{
    public const BUSY = 'Another change is being saved. Please try again.';

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly ManagerRegistry $registry,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     *
     * @throws EnrollmentException when another change is running or the save clashed with one
     */
    public function forSchool(School $school, callable $change): mixed
    {
        return $this->run('learners-school-'.$school->getId(), $change);
    }

    /**
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     *
     * @throws EnrollmentException when another change is running or the save clashed with one
     */
    public function forLearner(Learner $learner, callable $change): mixed
    {
        return $this->run('learner-'.$learner->getId(), $change);
    }

    private function run(string $key, callable $change): mixed
    {
        $lock = $this->lockFactory->createLock($key);
        if (!$lock->acquire()) {
            throw new EnrollmentException(self::BUSY);
        }

        try {
            return $change();
        } catch (UniqueConstraintViolationException) {
            // another request saved the same enrollment or username first; the failed flush
            // closed the entity manager, so give the rest of the request a fresh one
            $this->registry->resetManager();

            throw new EnrollmentException(self::BUSY);
        } finally {
            $lock->release();
        }
    }
}
