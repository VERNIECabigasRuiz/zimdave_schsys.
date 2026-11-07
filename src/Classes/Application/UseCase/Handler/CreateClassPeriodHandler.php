<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Handler;

use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Service\ClassPeriodManager;
use App\Classes\Application\UseCase\Command\CreateClassPeriodCommand;
use App\Classes\Domain\Model\ClassPeriod;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<CreateClassPeriodCommand> */
#[AsUseCaseHandler(CreateClassPeriodCommand::class)]
final class CreateClassPeriodHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly ClassesScope $scope,
        private readonly ClassPeriodManager $manager,
    ) {
    }

    /** @param CreateClassPeriodCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->locks->acquire(ClassPeriodManager::LOCK_PREFIX . $command->schoolId, ClassPeriodManager::BUSY);
        $period = (new ClassPeriod($this->scope->school($command->schoolId), $this->scope->currentYear()))
            ->setStartsAt($command->startsAt)
            ->setEndsAt($command->endsAt)
            ->setBreakLabel($command->isBreak ? $command->breakLabel : null);

        return Result::success(['label' => $this->manager->create($period)]);
    }
}
