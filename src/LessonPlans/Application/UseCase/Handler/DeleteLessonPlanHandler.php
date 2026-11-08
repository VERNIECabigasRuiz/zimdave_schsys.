<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\LessonPlans\Application\UseCase\Command\DeleteLessonPlanCommand;
use App\LessonPlans\Application\Service\LessonPlanPolicy;
use App\LessonPlans\Application\Service\LessonPlanScope;
use App\LessonPlans\Domain\Model\Repository\LessonPlanRepositoryInterface;

/** @implements UseCaseHandlerInterface<DeleteLessonPlanCommand> */
#[AsUseCaseHandler(DeleteLessonPlanCommand::class)]
final class DeleteLessonPlanHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly LessonPlanScope $scope,
        private readonly LessonPlanRepositoryInterface $plans,
    ) {
    }

    /** @param DeleteLessonPlanCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $this->plans->remove($this->scope->plan($actor, $command->planId, LessonPlanPolicy::EDIT));

        return Result::success(null);
    }
}
