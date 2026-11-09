<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\LessonPlans\Application\Query\Command\GetLessonPlanIdCommand;
use App\LessonPlans\Application\Service\LessonPlanScope;
use App\LessonPlans\Application\Service\LessonWeek;
use App\LessonPlans\Domain\Exception\LessonPlanNotFoundException;
use App\LessonPlans\Domain\Model\Repository\LessonPlanRepositoryInterface;

/** @implements QueryHandlerInterface<GetLessonPlanIdCommand> */
#[AsQueryHandler(GetLessonPlanIdCommand::class)]
final class GetLessonPlanIdHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LessonPlanScope $scope,
        private readonly LessonPlanRepositoryInterface $plans,
    ) {
    }

    /** @param GetLessonPlanIdCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $load = $this->scope->ownLoad($actor, $command->loadId);
        $plan = $this->plans->findOneFor($load, LessonWeek::monday($command->week))
            ?? throw new LessonPlanNotFoundException();

        return Result::success(['id' => $plan->getId()]);
    }
}
