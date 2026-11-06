<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Handler;

use App\Access\Application\UseCase\Command\ChangePasswordCommand;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Exception\PasswordUnchangedException;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Service\Password\PasswordHasherInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<ChangePasswordCommand> */
#[AsUseCaseHandler(ChangePasswordCommand::class)]
final class ChangePasswordHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly PasswordHasherInterface $hasher,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param ChangePasswordCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $user = $this->users->findById($command->userId) ?? throw new AccountNotFoundException();
        if ($this->hasher->isValid($user, $command->newPassword)) {
            throw new PasswordUnchangedException();
        }

        $user->setPassword($this->hasher->hash($user, $command->newPassword));
        $user->setMustChangePassword(false);
        $this->activityLogger->log('Changed password');

        return Result::success();
    }
}
