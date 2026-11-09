<?php

declare(strict_types=1);

namespace App\Billing\Application\Query\Handler;

use App\Billing\Application\Query\Command\GetPlanCommand;
use App\Billing\Application\Transformer\PlanTransformer;
use App\Billing\Domain\Exception\PlanNotFoundException;
use App\Billing\Domain\Model\Repository\PlanRepositoryInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<GetPlanCommand> */
#[AsQueryHandler(GetPlanCommand::class)]
final class GetPlanHandler implements QueryHandlerInterface
{
    public function __construct(private readonly PlanRepositoryInterface $plans)
    {
    }

    /** @param GetPlanCommand $command */
    public function __invoke(Query $command): Result
    {
        $plan = $this->plans->findById($command->id) ?? throw new PlanNotFoundException();

        return Result::success(PlanTransformer::toArray($plan));
    }
}
