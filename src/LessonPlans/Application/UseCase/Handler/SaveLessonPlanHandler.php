<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\LessonPlans\Application\UseCase\Command\SaveLessonPlanCommand;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\LessonPlans\Application\Service\LessonPlanPolicy;
use App\LessonPlans\Application\Service\LessonPlanScope;

/** @implements UseCaseHandlerInterface<SaveLessonPlanCommand> */
#[AsUseCaseHandler(SaveLessonPlanCommand::class)]
final class SaveLessonPlanHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly LessonPlanScope $scope,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param SaveLessonPlanCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $plan = $this->scope->plan($actor, $command->planId, LessonPlanPolicy::EDIT);
        $plan->setTopic($command->topic)
            ->setCompetencies($command->competencies)
            ->setClassNotes($command->classNotes)
            ->setDays($command->days)
            ->setReferences($command->references)
            ->setAiDeclaration($command->aiDeclaration)
            ->touch($this->clock->now());

        return Result::success(null);
    }
}
