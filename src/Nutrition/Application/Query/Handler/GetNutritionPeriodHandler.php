<?php

declare(strict_types=1);

namespace App\Nutrition\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Nutrition\Application\Query\Command\GetNutritionPeriodCommand;
use App\Nutrition\Application\Service\NutritionPolicy;
use App\Nutrition\Application\Service\NutritionScope;
use App\Nutrition\Application\Service\Sf8ReportBuilder;
use App\Nutrition\Domain\Service\Sf8\Sf8Row;

/** @implements QueryHandlerInterface<GetNutritionPeriodCommand> */
#[AsQueryHandler(GetNutritionPeriodCommand::class)]
final class GetNutritionPeriodHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly NutritionScope $scope,
        private readonly NutritionPolicy $policy,
        private readonly Sf8ReportBuilder $reports,
    ) {
    }

    /** @param GetNutritionPeriodCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->pageSection($actor, $command->schoolId, $command->sectionId);
        $report = $this->reports->build($section, $command->period);

        return Result::success([
            'section' => $section,
            'report' => $report,
            'canFill' => $this->policy->can($actor, NutritionPolicy::FILL, $section),
            'canReopen' => $this->policy->can($actor, NutritionPolicy::REOPEN, $section),
            'unweighed' => \count(array_filter($report->rows, static fn (Sf8Row $row) => !$row->weighed)),
        ]);
    }
}
