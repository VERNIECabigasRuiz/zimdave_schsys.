<?php

declare(strict_types=1);

namespace App\Learners\Application\Service;

use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Learners\Application\UseCase\Command\LearnerChange;
use App\Learners\Domain\Exception\LearnerNotFoundException;
use App\Learners\Domain\Exception\LearnersAccessDeniedException;

/** What every learner change does first: take its locks, then load and check the learner. */
class LearnerChanges
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly LearnersScope $scope,
        private readonly LearnerPolicy $policy,
    ) {
    }

    /** Takes the learner's lock, then the school's when $schoolId is given. */
    public function lock(int $learnerId, ?int $schoolId = null): void
    {
        $this->locks->acquire(LearnerEnrollment::LEARNER_LOCK . $learnerId, LearnerEnrollment::BUSY);
        if (null !== $schoolId) {
            $this->lockSchool($schoolId);
        }
    }

    public function lockSchool(int $schoolId): void
    {
        $this->locks->acquire(LearnerEnrollment::SCHOOL_LOCK . $schoolId, LearnerEnrollment::BUSY);
    }

    /**
     * Loads the learner the change names, once its locks are held. With $allowHistory an administrator may
     * act on a learner not enrolled at the page's school; $attribute, when given, is checked with the policy.
     *
     * @throws LearnerNotFoundException|LearnersAccessDeniedException
     */
    public function target(LearnerChange $command, ?string $attribute, bool $allowHistory): LearnerTarget
    {
        $actor = $this->scope->account($command->actorId);
        $page = $this->scope->page($command->schoolId, $command->fixedSectionId);
        [$learner, $current] = $this->scope->learner($page, $command->learnerId, $actor, $allowHistory);
        if (null !== $attribute) {
            $this->policy->deny($actor, $attribute, $learner);
        }

        return new LearnerTarget($actor, $page, $learner, $current);
    }
}
