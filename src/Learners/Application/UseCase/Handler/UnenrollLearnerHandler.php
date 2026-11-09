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
use App\Learners\Application\UseCase\Command\UnenrollLearnerCommand;

/** @implements UseCaseHandlerInterface<UnenrollLearnerCommand> */
#[AsUseCaseHandler(UnenrollLearnerCommand::class)]
final class UnenrollLearnerHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly LearnerChanges $changes,
        private readonly LearnerEnrollment $enrollment,
    ) {
    }

    /** @param UnenrollLearnerCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->changes->lock($command->learnerId, $command->schoolId);
        $target = $this->changes->target($command, LearnerPolicy::UNENROLL, false);
        $enrollment = $target->enrollment();
        $sectionLabel = $enrollment->getSection()->getLabel();
        $this->enrollment->unenroll($enrollment);

        return Result::success(
            ['fullName' => $target->learner->getUser()->getFullName(), 'sectionLabel' => $sectionLabel],
        );
    }
}
