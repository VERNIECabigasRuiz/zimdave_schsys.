<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Infrastructure\Lock;

use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Domain\Exception\ResourceBusyException;
use App\Core\Infrastructure\Lock\TransactionLock;
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

    public function testTheSameKeyCanBeTakenTwiceInOneUseCase(): void
    {
        // Initialize
        $locks = new TransactionLock($this->lockFactory);
        $locks->open();

        // Execute
        $locks->acquire('core-test', 'Busy, try again.');
        $locks->acquire('core-test', 'Busy, try again.');
        $heldInside = !$this->canLock();
        $locks->releaseAll();

        // Assert
        self::assertTrue($heldInside);
        self::assertTrue($this->canLock(), 'The lock was still held after the use case');
    }

    public function testANestedUseCaseKeepsTheLocksUntilTheOuterOneEnds(): void
    {
        // Initialize
        $locks = new TransactionLock($this->lockFactory);
        $locks->open();
        $locks->acquire('core-test', 'Busy, try again.');
        $locks->open();

        // Execute
        $locks->releaseAll();
        $heldAfterInner = !$this->canLock();
        $locks->releaseAll();

        // Assert
        self::assertTrue($heldAfterInner, 'The inner use case released the outer one\'s lock');
        self::assertTrue($this->canLock());
    }

    public function testLocksAreRefusedOutsideAUseCase(): void
    {
        // Expect
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Transaction locks are taken in use case handlers only.');

        // Initialize
        $locks = new TransactionLock($this->lockFactory);

        // Execute
        $locks->acquire('core-test', 'Busy, try again.');
    }

    public function testSharedHoldsCanBeTakenTogether(): void
    {
        // Initialize
        $locks = new TransactionLock($this->lockFactory);
        $locks->open();
        $other = $this->lockFactory->createLock('core-test');

        // Execute
        $locks->acquireShared('core-test', 'Busy, try again.');
        $otherShared = $other->acquireRead();
        $other->release();
        $heldInside = !$this->canLock();
        $locks->releaseAll();

        // Assert
        self::assertTrue($otherShared, 'A second shared hold was refused');
        self::assertTrue($heldInside, 'The shared hold let an exclusive lock in');
        self::assertTrue($this->canLock(), 'The shared hold was kept after the use case');
    }

    public function testASharedHoldIsRefusedWhileTheKeyIsHeldExclusively(): void
    {
        // Initialize
        $other = $this->lockFactory->createLock('core-test');
        self::assertTrue($other->acquire());
        $locks = new TransactionLock($this->lockFactory);
        $locks->open();

        // Execute
        try {
            $locks->acquireShared('core-test', 'Busy, try again.');
            $refusal = null;
        } catch (ResourceBusyException $e) {
            $refusal = $e->getMessage();
        }
        $other->release();
        $locks->releaseAll();

        // Assert
        self::assertSame('Busy, try again.', $refusal);
    }

    public function testAKeyAlreadyHeldIsNotTakenAgainShared(): void
    {
        // Initialize
        $locks = new TransactionLock($this->lockFactory);
        $locks->open();
        $locks->acquire('core-test', 'Busy, try again.');
        $other = $this->lockFactory->createLock('core-test');

        // Execute
        $locks->acquireShared('core-test', 'Busy, try again.');
        $otherShared = $other->acquireRead();
        $locks->releaseAll();

        // Assert
        self::assertFalse($otherShared, 'The exclusive lock became shared');
        self::assertTrue($this->canLock());
    }

    public function testSharedHoldsAreRefusedOutsideAUseCase(): void
    {
        // Expect
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Transaction locks are taken in use case handlers only.');

        // Initialize
        $locks = new TransactionLock($this->lockFactory);

        // Execute
        $locks->acquireShared('core-test', 'Busy, try again.');
    }

    public function testAnExtraReleaseDoesNothing(): void
    {
        // Initialize
        $locks = new TransactionLock($this->lockFactory);

        // Execute
        $locks->releaseAll();

        // Assert
        self::assertTrue($this->canLock());
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
