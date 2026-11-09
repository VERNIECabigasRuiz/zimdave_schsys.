<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Learners\Application\Service\LearnerChanges;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\Service\TransferRequests;
use App\Learners\Application\UseCase\Command\ApproveTransferCommand;

/** @implements UseCaseHandlerInterface<ApproveTransferCommand> */
#[AsUseCaseHandler(ApproveTransferCommand::class)]
final class ApproveTransferHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly LearnerChanges $changes,
        private readonly LearnersScope $scope,
        private readonly LearnerPolicy $policy,
        private readonly TransferRequests $transfers,
    ) {
    }

    /** @param ApproveTransferCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->changes->lock($command->learnerId, $command->toSchoolId);
        $actor = $this->scope->account($command->actorId);
        $request = $this->transfers->pending($command->transferId, $command->learnerId);
        $this->policy->deny($actor, LearnerPolicy::DECIDE_TRANSFER, $request);
        $this->transfers->approve($request, $actor);

        return Result::success([
            'fullName' => $request->getLearner()->getUser()->getFullName(),
            'schoolName' => $request->getToSchool()->getName(),
        ]);
    }
}
