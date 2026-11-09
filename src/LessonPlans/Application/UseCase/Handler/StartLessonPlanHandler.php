<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\LessonPlans\Application\UseCase\Command\StartLessonPlanCommand;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\LessonPlans\Application\Service\LessonPlanScope;
use App\LessonPlans\Application\Service\LessonWeek;
use App\LessonPlans\Domain\Model\Repository\LessonPlanRepositoryInterface;

/** @implements UseCaseHandlerInterface<StartLessonPlanCommand> */
#[AsUseCaseHandler(StartLessonPlanCommand::class)]
final class StartLessonPlanHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly LessonPlanScope $scope,
        private readonly LessonWeek $week,
        private readonly LessonPlanRepositoryInterface $plans,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param StartLessonPlanCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $load = $this->scope->ownLoad($actor, $command->loadId);
        $monday = LessonWeek::monday($command->week);
        if (null === $this->plans->findOneFor($load, $monday)) {
            $this->plans->add($this->week->start($load, $monday, $this->clock->now()));
        }

        return Result::success(null);
    }
}
