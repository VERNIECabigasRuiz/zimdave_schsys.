<?php

declare(strict_types=1);

namespace App\Nutrition\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Nutrition\Application\Query\Command\GetNutritionIndexCommand;
use App\Nutrition\Application\Service\NutritionScope;
use App\Nutrition\Application\Service\Sf8ReportBuilder;
use App\Nutrition\Domain\Model\NutritionPeriod;

/** @implements QueryHandlerInterface<GetNutritionIndexCommand> */
#[AsQueryHandler(GetNutritionIndexCommand::class)]
final class GetNutritionIndexHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly NutritionScope $scope,
        private readonly Sf8ReportBuilder $reports,
    ) {
    }

    /** @param GetNutritionIndexCommand $command */
    public function __invoke(Query $command): Result
    {
        $section = $this->scope->advisory($this->scope->account($command->actorId));

        return Result::success([
            'section' => $section,
            'reports' => array_map(
                fn (NutritionPeriod $period) => $this->reports->build($section, $period),
                NutritionPeriod::cases(),
            ),
        ]);
    }
}
