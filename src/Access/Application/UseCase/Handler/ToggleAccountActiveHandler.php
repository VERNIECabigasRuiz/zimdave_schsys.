<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Handler;

use App\Access\Application\Transformer\AccountTransformer;
use App\Access\Application\UseCase\Command\ToggleAccountActiveCommand;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Exception\OwnAccountException;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Service\Account\AccountReactivationGuardInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<ToggleAccountActiveCommand> */
#[AsUseCaseHandler(ToggleAccountActiveCommand::class)]
final class ToggleAccountActiveHandler implements UseCaseHandlerInterface
{
    /** @param iterable<AccountReactivationGuardInterface> $reactivationGuards every module's rule (wired in services.yaml) */
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly ActivityLoggerInterface $activityLogger,
        private readonly iterable $reactivationGuards,
    ) {
    }

    /** @param ToggleAccountActiveCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $account = $this->users->findById($command->id) ?? throw new AccountNotFoundException();
        if ($account->getUserIdentifier() === $command->actingUsername) {
            throw new OwnAccountException('You cannot deactivate your own account.');
        }

        if (!$account->isActive()) {
            foreach ($this->reactivationGuards as $guard) {
                $guard->assertCanReactivate($account);
            }
        }
        $account->setActive(!$account->isActive());
        $change = $account->isActive() ? 'Activated' : 'Deactivated';
        $this->activityLogger->log($change . ' account: ' . $account->getUsername());

        return Result::success(AccountTransformer::toArray($account));
    }
}
