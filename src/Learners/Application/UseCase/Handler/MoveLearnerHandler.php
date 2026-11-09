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
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\UseCase\Command\MoveLearnerCommand;

/** @implements UseCaseHandlerInterface<MoveLearnerCommand> */
#[AsUseCaseHandler(MoveLearnerCommand::class)]
final class MoveLearnerHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly LearnerChanges $changes,
        private readonly LearnersScope $scope,
        private readonly LearnerEnrollment $enrollment,
    ) {
    }

    /** @param MoveLearnerCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->changes->lock($command->learnerId, $command->schoolId);
        $target = $this->changes->target($command, LearnerPolicy::MOVE, false);
        $to = $this->scope->section($target->page, $command->sectionId);
        $this->enrollment->move($target->enrollment(), $to);

        return Result::success(
            ['fullName' => $target->learner->getUser()->getFullName(), 'sectionLabel' => $to->getLabel()],
        );
    }
}
