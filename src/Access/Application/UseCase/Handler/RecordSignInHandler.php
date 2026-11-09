<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Handler;

use App\Access\Application\UseCase\Command\RecordSignInCommand;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<RecordSignInCommand> */
#[AsUseCaseHandler(RecordSignInCommand::class)]
final class RecordSignInHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param RecordSignInCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $user = $this->users->findById($command->userId) ?? throw new AccountNotFoundException();
        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->activityLogger->log('Signed in', $user->getUserIdentifier());

        return Result::success();
    }
}
