<?php

declare(strict_types=1);

namespace App\Nutrition\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Nutrition\Application\Query\Command\GetSf8FileCommand;
use App\Nutrition\Application\Service\NutritionScope;
use App\Nutrition\Application\Service\Sf8ReportBuilder;
use App\Nutrition\Domain\Service\Sf8\Sf8SpreadsheetInterface;

/** @implements QueryHandlerInterface<GetSf8FileCommand> */
#[AsQueryHandler(GetSf8FileCommand::class)]
final class GetSf8FileHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly NutritionScope $scope,
        private readonly Sf8ReportBuilder $reports,
        private readonly Sf8SpreadsheetInterface $spreadsheet,
    ) {
    }

    /** @param GetSf8FileCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->pageSection($actor, $command->schoolId, $command->sectionId);
        $report = $this->reports->build($section, $command->period);

        return Result::success(['fileName' => $report->fileName(), 'content' => $this->spreadsheet->xlsx($report)]);
    }
}
