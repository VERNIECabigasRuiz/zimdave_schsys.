<?php

declare(strict_types=1);

namespace App\Core\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Query\Command\GetRecentActivityCommand;
use App\Core\Application\Transformer\ActivityLogTransformer;
use App\Core\Domain\Model\Repository\ActivityLogRepositoryInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<GetRecentActivityCommand> */
#[AsQueryHandler(GetRecentActivityCommand::class)]
final class GetRecentActivityHandler implements QueryHandlerInterface
{
    public function __construct(private readonly ActivityLogRepositoryInterface $entries)
    {
    }

    /** @param GetRecentActivityCommand $command */
    public function __invoke(Query $command): Result
    {
        return Result::success(array_map(
            ActivityLogTransformer::toArray(...),
            $this->entries->findRecent($command->limit),
        ));
    }
}
