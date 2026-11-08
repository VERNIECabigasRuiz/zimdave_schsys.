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
use App\Learners\Application\UseCase\Command\ResetLearnerPasswordCommand;

/** @implements UseCaseHandlerInterface<ResetLearnerPasswordCommand> */
#[AsUseCaseHandler(ResetLearnerPasswordCommand::class)]
final class ResetLearnerPasswordHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly LearnerChanges $changes,
        private readonly LearnerEnrollment $enrollment,
    ) {
    }

    /** @param ResetLearnerPasswordCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->changes->lock($command->learnerId);
        $target = $this->changes->target($command, LearnerPolicy::RESET_PASSWORD, true);
        $user = $target->learner->getUser();

        return Result::success([
            'fullName' => $user->getFullName(),
            'username' => $user->getUsername(),
            'password' => $this->enrollment->resetPassword($target->learner),
        ]);
    }
}
