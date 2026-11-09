<?php

declare(strict_types=1);

namespace App\Access\Domain\Service\Account;

use App\Access\Domain\Model\User;

/**
 * A rule another module has about changing an account's role.
 * Implemented by the modules that own the rule; every implementation is asked.
 */
interface AccountRoleChangeGuardInterface
{
    /**
     * Why $account may not move from $originalRole to its new role (already set on it), or null.
     */
    public function refusal(User $account, ?string $originalRole): ?string;
}
