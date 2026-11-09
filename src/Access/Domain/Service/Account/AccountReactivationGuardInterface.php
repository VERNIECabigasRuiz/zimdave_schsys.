<?php

declare(strict_types=1);

namespace App\Access\Domain\Service\Account;

use App\Access\Domain\Exception\AccountReactivationRefusedException;
use App\Access\Domain\Model\User;
use App\Core\Domain\Exception\ResourceBusyException;

/**
 * A rule another module has about reactivating an account, e.g. a teacher taking a plan seat.
 * Implemented by the modules that own the rule; every implementation is asked.
 */
interface AccountReactivationGuardInterface
{
    /**
     * May take transaction locks, which are held until the change is saved.
     *
     * @throws AccountReactivationRefusedException|ResourceBusyException
     */
    public function assertCanReactivate(User $account): void;
}
