<?php

namespace App\Service\AccessHooks;

use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Account\AccountRoleChangeGuardInterface;
use App\Repository\LearnerRepository;

/**
 * A learner's account stays a student account; the learner record is what makes it one.
 */
class LearnerRoleChangeGuard implements AccountRoleChangeGuardInterface
{
    public function __construct(private readonly LearnerRepository $learners)
    {
    }

    public function refusal(User $account, ?string $originalRole): ?string
    {
        if ($originalRole === $account->getRole()) {
            return null;
        }

        $learner = $this->learners->findOneByUser($account);

        return null !== $learner ? \sprintf('This account belongs to learner %s.', $learner->getLrn()) : null;
    }
}
