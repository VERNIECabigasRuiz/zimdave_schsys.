<?php

declare(strict_types=1);

namespace App\Learners\Infrastructure\Access;

use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Account\AccountLearnerLinkInterface;
use App\Learners\Domain\Model\Repository\LearnerRepositoryInterface;

class LearnerAccountLink implements AccountLearnerLinkInterface
{
    public function __construct(private readonly LearnerRepositoryInterface $learners)
    {
    }

    public function learnerLrn(User $account): ?string
    {
        return $this->learners->findOneByUser($account)?->getLrn();
    }
}
