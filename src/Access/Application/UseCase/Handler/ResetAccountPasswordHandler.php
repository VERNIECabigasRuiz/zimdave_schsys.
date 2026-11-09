<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Handler;

use App\Access\Application\UseCase\Command\ResetAccountPasswordCommand;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Exception\OwnAccountException;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Service\Password\PasswordHasherInterface;
use App\Access\Domain\Service\Password\TemporaryPasswordGenerator;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<ResetAccountPasswordCommand> */
#[AsUseCaseHandler(ResetAccountPasswordCommand::class)]
final class ResetAccountPasswordHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly PasswordHasherInterface $hasher,
        private readonly TemporaryPasswordGenerator $passwordGenerator,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param ResetAccountPasswordCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $account = $this->users->findById($command->id) ?? throw new AccountNotFoundException();
        if ($account->getUserIdentifier() === $command->actingUsername) {
            throw new OwnAccountException('Use "Change password" to change your own password.');
        }

        $temporaryPassword = $this->passwordGenerator->generate();
        $account->setPassword($this->hasher->hash($account, $temporaryPassword));
        $account->setMustChangePassword(true);
        $this->activityLogger->log('Reset password: ' . $account->getUsername());

        return Result::success([
            'fullName' => $account->getFullName(),
            'username' => $account->getUsername(),
            'password' => $temporaryPassword,
        ]);
    }
}
