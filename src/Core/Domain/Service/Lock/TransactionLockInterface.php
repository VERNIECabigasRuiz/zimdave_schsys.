<?php

declare(strict_types=1);

namespace App\Core\Domain\Service\Lock;

use App\Core\Domain\Exception\ResourceBusyException;

/**
 * Locks for use case handlers. A lock is held until the use case's database transaction
 * has committed or rolled back, so a concurrent change never sees the data half saved.
 */
interface TransactionLockInterface
{
    /**
     * Takes the lock on $resource without waiting. Only inside a use case handler; taking a lock
     * the use case already holds does nothing.
     *
     * @throws ResourceBusyException with $busyMessage when another change holds it
     */
    public function acquire(string $resource, string $busyMessage): void;
}
