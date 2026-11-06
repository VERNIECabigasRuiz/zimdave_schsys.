<?php

declare(strict_types=1);

namespace App\Billing\Application\Query\Handler;

use App\Billing\Application\Query\Command\ListPlansCommand;
use App\Billing\Application\Transformer\PlanTransformer;
use App\Billing\Domain\Model\Repository\PlanRepositoryInterface;
use App\Billing\Domain\Service\PlanUsageInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<ListPlansCommand> */
#[AsQueryHandler(ListPlansCommand::class)]
final class ListPlansHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly PlanRepositoryInterface $plans,
        private readonly PlanUsageInterface $usage,
    ) {
    }

    /** @param ListPlansCommand $command */
    public function __invoke(Query $command): Result
    {
        return Result::success([
            'plans' => array_map(PlanTransformer::toArray(...), $this->plans->findAllOrdered()),
            'schoolCounts' => $this->usage->schoolCountsByPlan(),
        ]);
    }
}
