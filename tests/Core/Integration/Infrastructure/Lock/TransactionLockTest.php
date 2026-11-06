<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Infrastructure\Lock;

use App\Core\Application\Bus\UseCaseBusInterface;
use App\Tests\Core\Integration\Bus\Fixture\LockThenSaveCommand;
use App\Tests\Core\Integration\Bus\Fixture\LockThenWaitCommand;
use App\Tests\Core\Integration\Bus\Fixture\LockThenWaitHandler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;

final class TransactionLockTest extends KernelTestCase
{
    private UseCaseBusInterface $bus;
    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->bus = static::getContainer()->get(UseCaseBusInterface::class);
        $this->lockFactory = static::getContainer()->get(LockFactory::class);
        LockThenWaitHandler::$otherCouldLock = null;
    }

    public function testLockIsHeldUntilTheUseCaseCommits(): void
    {
        // Execute
        $result = $this->bus->handle(new LockThenWaitCommand());

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertFalse(LockThenWaitHandler::$otherCouldLock, 'Another request took the lock inside the use case');
        self::assertTrue($this->canLock(), 'The lock was still held after the use case');
    }

    public function testLockIsStillHeldWhenTheChangesAreSaved(): void
    {
        // Initialize
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $listener = new class (fn () => !$this->canLock()) {
            public ?bool $lockedWhileSaving = null;

            /** @param \Closure(): bool $isLocked */
            public function __construct(private readonly \Closure $isLocked)
            {
            }

            public function postFlush(): void
            {
                $this->lockedWhileSaving = ($this->isLocked)();
            }
        };
        $em->getEventManager()->addEventListener(Events::postFlush, $listener);

        // Execute
        $result = $this->bus->handle(new LockThenSaveCommand());
        $em->getEventManager()->removeEventListener(Events::postFlush, $listener);

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertTrue($listener->lockedWhileSaving, 'The lock was released before the changes were saved');
        self::assertTrue($this->canLock(), 'The lock was still held after the use case');
    }

    public function testBusyResourceIsRefused(): void
    {
        // Initialize
        $other = $this->lockFactory->createLock('core-test');
        self::assertTrue($other->acquire());

        // Execute
        $result = $this->bus->handle(new LockThenWaitCommand());
        $other->release();

        // Assert
        self::assertSame('RESOURCE_BUSY', $result->errorCode());
        self::assertSame('Busy, try again.', $result->exception()?->getMessage());
        self::assertNull(LockThenWaitHandler::$otherCouldLock, 'The handler went on without the lock');
    }

    public function testLocksAreReleasedAfterAFailure(): void
    {
        // Execute
        $result = $this->bus->handle(new LockThenWaitCommand(fail: true));

        // Assert
        self::assertSame('INTERNAL_ERROR', $result->errorCode());
        self::assertTrue($this->canLock(), 'The lock was still held after the failed use case');
    }

    private function canLock(): bool
    {
        $lock = $this->lockFactory->createLock('core-test');
        $acquired = $lock->acquire();
        if ($acquired) {
            $lock->release();
        }

        return $acquired;
    }
}
