<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus\Fixture;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use Symfony\Component\Lock\LockFactory;

/**
 * Records whether another request could take the same lock while this handler holds it.
 *
 * @implements UseCaseHandlerInterface<LockThenWaitCommand>
 */
#[AsUseCaseHandler(LockThenWaitCommand::class)]
final class LockThenWaitHandler implements UseCaseHandlerInterface
{
    public static ?bool $otherCouldLock = null;

    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /** @param LockThenWaitCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->locks->acquire('core-test', 'Busy, try again.');

        $other = $this->lockFactory->createLock('core-test');
        self::$otherCouldLock = $other->acquire();
        if (self::$otherCouldLock) {
            $other->release();
        }

        if ($command->fail) {
            throw new \RuntimeException('Failed while holding the lock.');
        }

        return Result::success();
    }
}
