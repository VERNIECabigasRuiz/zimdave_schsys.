<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Lock;

use App\Core\Domain\Exception\ResourceBusyException;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

class TransactionLock implements TransactionLockInterface
{
    /** @var array<string, SharedLockInterface> held locks keyed by resource */
    private array $held = [];

    /** How many use cases are running (one inside another counts twice). */
    private int $openUseCases = 0;

    public function __construct(private readonly LockFactory $lockFactory)
    {
    }

    public function acquire(string $resource, string $busyMessage): void
    {
        $this->take($resource, $busyMessage, shared: false);
    }

    public function acquireShared(string $resource, string $busyMessage): void
    {
        $this->take($resource, $busyMessage, shared: true);
    }

    private function take(string $resource, string $busyMessage, bool $shared): void
    {
        if (0 === $this->openUseCases) {
            throw new \LogicException('Transaction locks are taken in use case handlers only.');
        }
        if (isset($this->held[$resource])) {
            return;
        }

        $lock = $this->lockFactory->createLock($resource);
        if (!($shared ? $lock->acquireRead() : $lock->acquire())) {
            throw new ResourceBusyException($busyMessage);
        }
        $this->held[$resource] = $lock;
    }

    /** A use case starts; its locks are kept until the outermost one ends. */
    public function open(): void
    {
        ++$this->openUseCases;
    }

    /** A use case ended; the outermost one releases every lock taken meanwhile. */
    public function releaseAll(): void
    {
        $this->openUseCases = max(0, $this->openUseCases - 1);
        if ($this->openUseCases > 0) {
            return;
        }
        foreach ($this->held as $lock) {
            $lock->release();
        }
        $this->held = [];
    }
}
