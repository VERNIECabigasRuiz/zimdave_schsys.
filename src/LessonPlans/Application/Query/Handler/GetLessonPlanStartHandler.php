<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\LessonPlans\Application\Query\Command\GetLessonPlanStartCommand;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\LessonPlans\Application\Service\LessonPlanScope;

/** @implements QueryHandlerInterface<GetLessonPlanStartCommand> */
#[AsQueryHandler(GetLessonPlanStartCommand::class)]
final class GetLessonPlanStartHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LessonPlanScope $scope,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param GetLessonPlanStartCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);

        return Result::success([
            'loads' => $this->scope->teacherLoads($actor),
            'today' => $this->clock->now()->setTime(0, 0),
        ]);
    }
}
