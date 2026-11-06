<?php

declare(strict_types=1);

namespace App\Access\Domain\Service\Account;

use App\Access\Domain\Model\User;

/** Tells which learner an account belongs to; implemented by the learners module. */
interface AccountLearnerLinkInterface
{
    /** The LRN of the learner whose account this is, or null. */
    public function learnerLrn(User $account): ?string;
}
