<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Lock;

use App\Core\Domain\Exception\ResourceBusyException;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

class TransactionLock implements TransactionLockInterface
{
    /** @var list<SharedLockInterface> */
    private array $held = [];

    public function __construct(private readonly LockFactory $lockFactory)
    {
    }

    public function acquire(string $resource, string $busyMessage): void
    {
        $lock = $this->lockFactory->createLock($resource);
        if (!$lock->acquire()) {
            throw new ResourceBusyException($busyMessage);
        }
        $this->held[] = $lock;
    }

    /** Releases every lock taken since the last call. */
    public function releaseAll(): void
    {
        foreach ($this->held as $lock) {
            $lock->release();
        }
        $this->held = [];
    }
}
