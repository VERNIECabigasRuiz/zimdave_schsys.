<?php

namespace App\Grading;

use App\Entity\School;
use App\Entity\Section;
use App\Entity\Subject;
use App\Service\LearnerLock;
use Symfony\Component\Lock\LockFactory;

/**
 * The locks that keep grade changes apart: a class's own lock, held while its grades change, and the school's grade
 * lock, held exclusively while the principal changes locks or openings and shared while a class's grades change.
 * Never blocks: a lock that is taken refuses with LearnerLock::BUSY.
 */
class GradeLocks
{
    public function __construct(private readonly LockFactory $lockFactory)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     *
     * @throws GradingException
     */
    public function class(Section $section, Subject $subject, callable $change): mixed
    {
        return $this->locked(\sprintf('grades-%d-%d', $section->getId(), $subject->getId()), $change);
    }

    /**
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     *
     * @throws GradingException
     */
    public function school(School $school, callable $change, bool $shared = false): mixed
    {
        return $this->locked('grade-lock-'.$school->getId(), $change, $shared);
    }

    /**
     * Runs $change holding the lock, exclusively or, when $shared, alongside other shared holders (never blocking).
     *
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     */
    private function locked(string $key, callable $change, bool $shared = false): mixed
    {
        $lock = $this->lockFactory->createLock($key);
        if (!($shared ? $lock->acquireRead() : $lock->acquire())) {
            throw new GradingException(LearnerLock::BUSY);
        }

        try {
            return $change();
        } finally {
            $lock->release();
        }
    }
}
