<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradeRecorder;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Application\UseCase\Command\LockGradingPeriodCommand;

/** @implements UseCaseHandlerInterface<LockGradingPeriodCommand> */
#[AsUseCaseHandler(LockGradingPeriodCommand::class)]
final class LockGradingPeriodHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly GradeRecorder $recorder,
    ) {
    }

    /** @param LockGradingPeriodCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->locks->acquire(GradeRecorder::schoolLock($command->schoolId), GradeRecorder::BUSY);
        $actor = $this->scope->account($command->actorId);
        $school = $this->scope->school($command->schoolId);
        $this->policy->deny($actor, GradePolicy::LOCK, $school);
        $period = $this->scope->currentPeriod($command->periodId);
        $this->recorder->lockPeriod($school, $period, $actor);

        return Result::success($period->getLabel());
    }
}
