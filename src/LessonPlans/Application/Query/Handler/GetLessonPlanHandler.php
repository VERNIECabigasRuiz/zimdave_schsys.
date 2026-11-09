<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\LessonPlans\Application\Query\Command\GetLessonPlanCommand;
use App\LessonPlans\Application\Service\LessonPlanScope;

/** @implements QueryHandlerInterface<GetLessonPlanCommand> */
#[AsQueryHandler(GetLessonPlanCommand::class)]
final class GetLessonPlanHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LessonPlanScope $scope,
    ) {
    }

    /** @param GetLessonPlanCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);

        return Result::success(['plan' => $this->scope->plan($actor, $command->planId, $command->attribute)]);
    }
}
