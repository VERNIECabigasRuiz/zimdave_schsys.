<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\LessonPlans\Application\Query\Command\GetTeacherLessonPlansCommand;
use App\LessonPlans\Application\Service\LessonPlanScope;
use App\LessonPlans\Domain\Model\Repository\LessonPlanRepositoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** @implements QueryHandlerInterface<GetTeacherLessonPlansCommand> */
#[AsQueryHandler(GetTeacherLessonPlansCommand::class)]
final class GetTeacherLessonPlansHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LessonPlanScope $scope,
        private readonly SchoolDirectoryInterface $schools,
        private readonly LessonPlanRepositoryInterface $plans,
    ) {
    }

    /** @param GetTeacherLessonPlansCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $schoolYear = $this->schools->currentYear();

        return Result::success([
            'plans' => null === $schoolYear ? [] : $this->plans->findForTeacher($actor, $schoolYear),
        ]);
    }
}
