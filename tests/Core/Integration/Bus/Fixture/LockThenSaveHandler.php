<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus\Fixture;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<LockThenSaveCommand> */
#[AsUseCaseHandler(LockThenSaveCommand::class)]
final class LockThenSaveHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param LockThenSaveCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->locks->acquire('core-test', 'Busy, try again.');
        $this->activityLogger->log('saved under lock', 'core-test');

        return Result::success();
    }
}
