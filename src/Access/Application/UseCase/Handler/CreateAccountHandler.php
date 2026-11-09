<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Handler;

use App\Access\Application\UseCase\Command\CreateAccountCommand;
use App\Access\Domain\Exception\UsernameTakenException;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Password\PasswordHasherInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<CreateAccountCommand> */
#[AsUseCaseHandler(CreateAccountCommand::class)]
final class CreateAccountHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly PasswordHasherInterface $hasher,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param CreateAccountCommand $command */
    public function __invoke(UseCase $command): Result
    {
        if (null !== $this->users->findOneByUsername($command->username)) {
            throw new UsernameTakenException();
        }

        $user = (new User())
            ->setUsername($command->username)
            ->setFullName($command->fullName)
            ->setRole($command->role)
            ->setMustChangePassword(true);
        $user->setPassword($this->hasher->hash($user, $command->temporaryPassword));
        $this->users->add($user);
        $this->activityLogger->log('Created account: ' . $user->getUsername());

        // The id is only known once the bus commits, so the result leaves it out
        return Result::success([
            'fullName' => $user->getFullName(),
            'username' => $user->getUsername(),
            'role' => $user->getRole(),
        ]);
    }
}
