<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\LessonPlans\Application\Query\Command\GetSchoolLessonPlansCommand;
use App\Access\Domain\Model\User;
use App\LessonPlans\Application\Service\LessonPlanScope;
use App\LessonPlans\Domain\Model\Repository\LessonPlanRepositoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** @implements QueryHandlerInterface<GetSchoolLessonPlansCommand> */
#[AsQueryHandler(GetSchoolLessonPlansCommand::class)]
final class GetSchoolLessonPlansHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LessonPlanScope $scope,
        private readonly SchoolDirectoryInterface $schools,
        private readonly LessonPlanRepositoryInterface $plans,
    ) {
    }

    /** @param GetSchoolLessonPlansCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $schoolYear = $this->schools->currentYear();
        $school = $this->scope->schoolOf($actor, User::ROLE_PRINCIPAL);

        return Result::success([
            'school' => $school,
            'schoolYear' => $schoolYear,
            'plans' => null === $school || null === $schoolYear ? [] : $this->plans->findForSchool(
                $school,
                $schoolYear,
            ),
        ]);
    }
}
