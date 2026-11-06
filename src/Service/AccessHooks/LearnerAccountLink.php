<?php

namespace App\Service\AccessHooks;

use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Account\AccountLearnerLinkInterface;
use App\Repository\LearnerRepository;

class LearnerAccountLink implements AccountLearnerLinkInterface
{
    public function __construct(private readonly LearnerRepository $learners)
    {
    }

    public function learnerLrn(User $account): ?string
    {
        return $this->learners->findOneByUser($account)?->getLrn();
    }
}
