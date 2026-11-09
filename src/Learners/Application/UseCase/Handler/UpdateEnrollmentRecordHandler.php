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
use App\Learners\Application\UseCase\Command\UpdateEnrollmentRecordCommand;

/** @implements UseCaseHandlerInterface<UpdateEnrollmentRecordCommand> */
#[AsUseCaseHandler(UpdateEnrollmentRecordCommand::class)]
final class UpdateEnrollmentRecordHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly LearnerChanges $changes,
        private readonly LearnerEnrollment $enrollment,
    ) {
    }

    /** @param UpdateEnrollmentRecordCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->changes->lock($command->learnerId);
        $target = $this->changes->target($command, LearnerPolicy::EDIT, false);
        $record = $target->enrollment()
            ->setLearningModality($command->learningModality)
            ->setRemarkCode($command->remarkCode)
            ->setRemarkDetails($command->remarkDetails)
            ->setRemarkDate($command->remarkDate);
        $this->enrollment->updateRecord($record);

        return Result::success(['fullName' => $target->learner->getUser()->getFullName()]);
    }
}
