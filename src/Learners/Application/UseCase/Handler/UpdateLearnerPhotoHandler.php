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
use App\Learners\Application\UseCase\Command\UpdateLearnerPhotoCommand;

/** @implements UseCaseHandlerInterface<UpdateLearnerPhotoCommand> */
#[AsUseCaseHandler(UpdateLearnerPhotoCommand::class)]
final class UpdateLearnerPhotoHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly LearnerChanges $changes,
        private readonly LearnerEnrollment $enrollment,
    ) {
    }

    /** @param UpdateLearnerPhotoCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->changes->lock($command->learnerId);
        $target = $this->changes->target($command, LearnerPolicy::EDIT, false);
        $this->enrollment->updatePhoto($target->learner, $command->path);

        return Result::success();
    }
}
