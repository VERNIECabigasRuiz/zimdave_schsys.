<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Learners\Application\Service\LearnerChanges;
use App\Learners\Application\Service\LearnerEnrollment;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\UseCase\Command\UpdateLearnerCommand;

/** @implements UseCaseHandlerInterface<UpdateLearnerCommand> */
#[AsUseCaseHandler(UpdateLearnerCommand::class)]
final class UpdateLearnerHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly LearnerChanges $changes,
        private readonly LearnerPolicy $policy,
        private readonly LearnerEnrollment $enrollment,
    ) {
    }

    /** @param UpdateLearnerCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->changes->lock($command->learnerId);
        $target = $this->changes->target($command, LearnerPolicy::EDIT, true);
        $withLrn = $command->withLrn && $this->policy->can($target->actor, LearnerPolicy::CHANGE_LRN, $target->learner);
        $command->details->applyTo($target->learner);
        $this->enrollment->update($target->learner, $withLrn ? $command->details->lrn : null);

        return Result::success(['fullName' => $target->learner->getUser()->getFullName()]);
    }
}
