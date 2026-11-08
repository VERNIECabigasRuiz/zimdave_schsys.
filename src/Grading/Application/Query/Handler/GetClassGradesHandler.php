<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Grading\Application\Query\Command\GetClassGradesCommand;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Application\Service\SectionGradeGrid;

/** @implements QueryHandlerInterface<GetClassGradesCommand> */
#[AsQueryHandler(GetClassGradesCommand::class)]
final class GetClassGradesHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly GradingScope $scope,
        private readonly SectionGradeGrid $grid,
    ) {
    }

    /** @param GetClassGradesCommand $command */
    public function __invoke(Query $command): Result
    {
        $section = $this->scope->advisory($this->scope->account($command->actorId));
        $schoolYear = $section->getSchoolYear();
        $selected = $this->scope->selectPeriod($section->getSchool(), $schoolYear, $command->periodId);

        return Result::success([
            'section' => $section,
            'periods' => $schoolYear->getGradingPeriods(),
            'period' => $selected,
            'grid' => null === $selected ? null : $this->grid->build($section, $selected),
        ]);
    }
}
