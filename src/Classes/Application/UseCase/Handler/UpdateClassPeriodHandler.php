<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Handler;

use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Service\ClassPeriodManager;
use App\Classes\Application\UseCase\Command\UpdateClassPeriodCommand;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<UpdateClassPeriodCommand> */
#[AsUseCaseHandler(UpdateClassPeriodCommand::class)]
final class UpdateClassPeriodHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly ClassesScope $scope,
        private readonly ClassPeriodManager $manager,
    ) {
    }

    /** @param UpdateClassPeriodCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->locks->acquire(ClassPeriodManager::LOCK_PREFIX . $command->schoolId, ClassPeriodManager::BUSY);
        $school = $this->scope->school($command->schoolId);
        $period = $this->scope->period($school, $this->scope->currentYear(), $command->id);

        return Result::success(['label' => $this->manager->update($period, $command)]);
    }
}
