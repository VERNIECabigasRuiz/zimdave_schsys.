<?php

declare(strict_types=1);

namespace App\Nutrition\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Nutrition\Application\Query\Command\GetNutritionOverviewCommand;
use App\Nutrition\Application\Service\NutritionScope;
use App\Nutrition\Application\Service\Sf8ReportBuilder;
use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Nutrition\Domain\Service\Sf8\Sf8Report;
use App\Nutrition\Domain\Service\Sf8\Sf8Summary;

/** @implements QueryHandlerInterface<GetNutritionOverviewCommand> */
#[AsQueryHandler(GetNutritionOverviewCommand::class)]
final class GetNutritionOverviewHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly NutritionScope $scope,
        private readonly Sf8ReportBuilder $reports,
    ) {
    }

    /** @param GetNutritionOverviewCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->scope->currentYear();
        $period = $command->compare ? NutritionPeriod::Baseline : $command->period;
        $reports = $this->reports->buildForSchool($school, $schoolYear, $period);
        $endlines = [];
        if ($command->compare) {
            foreach ($this->reports->buildForSchool($school, $schoolYear, NutritionPeriod::Endline) as $report) {
                $endlines[(int) $report->section->getId()] = $report;
            }
        }

        return Result::success([
            'school' => $school,
            'schoolYear' => $schoolYear,
            'reports' => $reports,
            'endlines' => $endlines,
            'total' => Sf8Summary::sum(array_map(static fn (Sf8Report $r) => $r->summary, $reports)),
            'endlineTotal' => $command->compare
                ? Sf8Summary::sum(array_map(static fn (Sf8Report $r) => $r->summary, array_values($endlines)))
                : null,
        ]);
    }
}
