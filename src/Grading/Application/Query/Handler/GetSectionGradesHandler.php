<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Grading\Application\Query\Command\GetSectionGradesCommand;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Application\Service\SectionGradeGrid;

/** @implements QueryHandlerInterface<GetSectionGradesCommand> */
#[AsQueryHandler(GetSectionGradesCommand::class)]
final class GetSectionGradesHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly SectionGradeGrid $grid,
    ) {
    }

    /** @param GetSectionGradesCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $school = $this->scope->school($command->schoolId);
        $section = $this->scope->section($school, $command->sectionId);
        $this->policy->deny($actor, GradePolicy::VIEW_SECTION, $section);
        $schoolYear = $section->getSchoolYear();
        $selected = $this->scope->selectPeriod($school, $schoolYear, $command->periodId);

        return Result::success([
            'school' => $school,
            'section' => $section,
            'periods' => $schoolYear->getGradingPeriods(),
            'period' => $selected,
            'grid' => null === $selected ? null : $this->grid->build($section, $selected),
        ]);
    }
}
