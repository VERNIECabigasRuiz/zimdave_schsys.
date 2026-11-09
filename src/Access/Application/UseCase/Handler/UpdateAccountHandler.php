<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Handler;

use App\Access\Application\Transformer\AccountTransformer;
use App\Access\Application\UseCase\Command\UpdateAccountCommand;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Exception\AccountRoleChangeRefusedException;
use App\Access\Domain\Exception\UsernameTakenException;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Service\Account\AccountRoleChangeGuardInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<UpdateAccountCommand> */
#[AsUseCaseHandler(UpdateAccountCommand::class)]
final class UpdateAccountHandler implements UseCaseHandlerInterface
{
    /** @param iterable<AccountRoleChangeGuardInterface> $roleGuards every module's rule (wired in services.yaml) */
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly ActivityLoggerInterface $activityLogger,
        private readonly iterable $roleGuards,
    ) {
    }

    /** @param UpdateAccountCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $account = $this->users->findById($command->id) ?? throw new AccountNotFoundException();
        $owner = $this->users->findOneByUsername($command->username);
        if (null !== $owner && $owner !== $account) {
            throw new UsernameTakenException();
        }

        $originalRole = $account->getRole();
        $account->setRole($command->role)
            ->setFullName($command->fullName)
            ->setUsername($command->username)
            ->setActive($command->active);

        $reasons = [];
        foreach ($this->roleGuards as $guard) {
            $reason = $guard->refusal($account, $originalRole);
            if (null !== $reason) {
                $reasons[] = $reason;
            }
        }
        if ([] !== $reasons) {
            throw new AccountRoleChangeRefusedException($reasons);
        }

        $this->activityLogger->log('Updated account: ' . $account->getUsername());

        return Result::success(AccountTransformer::toArray($account));
    }
}
