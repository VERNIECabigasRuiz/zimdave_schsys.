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
use App\Grading\Application\UseCase\Command\WithdrawGradeChangeCommand;
use App\Grading\Domain\Exception\GradingNotFoundException;

/** @implements UseCaseHandlerInterface<WithdrawGradeChangeCommand> */
#[AsUseCaseHandler(WithdrawGradeChangeCommand::class)]
final class WithdrawGradeChangeHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly GradeChangeManager $manager,
    ) {
    }

    /** @param WithdrawGradeChangeCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->locks->acquire(GradeRecorder::schoolLock($command->schoolId), GradeRecorder::BUSY);

        $actor = $this->scope->account($command->actorId);
        $request = $this->scope->changeRequest($command->requestId);
        if ($request->getSection()->getSchool()->getId() !== $command->schoolId) {
            throw new GradingNotFoundException();
        }
        $this->policy->deny($actor, GradePolicy::CHANGE_WITHDRAW, $request);
        $this->manager->withdraw($request, $actor);

        return Result::success(null);
    }
}
