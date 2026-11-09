<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Query\Command\ListDivisionsCommand;
use App\Schools\Application\Transformer\DivisionTransformer;
use App\Schools\Domain\Model\Division;
use App\Schools\Domain\Model\Repository\DivisionRepositoryInterface;

/** @implements QueryHandlerInterface<ListDivisionsCommand> */
#[AsQueryHandler(ListDivisionsCommand::class)]
final class ListDivisionsHandler implements QueryHandlerInterface
{
    public function __construct(private readonly DivisionRepositoryInterface $divisions)
    {
    }

    /** @param ListDivisionsCommand $command */
    public function __invoke(Query $command): Result
    {
        return Result::success(array_map(
            static fn (Division $division) => DivisionTransformer::toArray($division),
            $this->divisions->findAllOrdered(),
        ));
    }
}
