<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Grading\Application\Service\GradeChangeManager;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradeRecorder;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Application\UseCase\Command\DeclineGradeChangeCommand;

/** @implements UseCaseHandlerInterface<DeclineGradeChangeCommand> */
#[AsUseCaseHandler(DeclineGradeChangeCommand::class)]
final class DeclineGradeChangeHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly GradeChangeManager $manager,
    ) {
    }

    /** @param DeclineGradeChangeCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->locks->acquire(GradeRecorder::schoolLock($command->schoolId), GradeRecorder::BUSY);

        $actor = $this->scope->account($command->actorId);
        $request = $this->scope->changeRequest($command->requestId, $this->scope->school($command->schoolId));
        $this->policy->deny($actor, GradePolicy::CHANGE_DECIDE, $request);
        $this->manager->decline($request, $actor, $command->note);

        return Result::success(null);
    }
}
