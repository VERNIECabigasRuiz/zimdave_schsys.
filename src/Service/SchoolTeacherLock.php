<?php

namespace App\Service;

use App\Entity\School;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Lock\LockFactory;

/**
 * Runs one change to a school's teachers at a time, so two requests can't both pass
 * the plan limit check and then both save.
 */
class SchoolTeacherLock
{
    public function __construct(
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * Runs $change (check, persist and flush) while holding the school's lock.
     *
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     *
     * @throws PlanLimitException when another change is running or the save clashed with one
     */
    public function run(School $school, callable $change): mixed
    {
        $lock = $this->lockFactory->createLock('school-teachers-'.$school->getId());
        if (!$lock->acquire()) {
            throw new PlanLimitException("Another change to this school's teachers is being saved. Please try again.");
        }

        try {
            return $change();
        } catch (UniqueConstraintViolationException) {
            // e.g. a double-submitted form: the first request already saved the same teacher or username
            throw new PlanLimitException('This was already saved by another request. Please check the list and try again.');
        } finally {
            $lock->release();
        }
    }
}
