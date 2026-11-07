<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Query\Command\GetDivisionCommand;
use App\Schools\Application\Transformer\DivisionTransformer;
use App\Schools\Domain\Exception\DivisionNotFoundException;
use App\Schools\Domain\Model\Repository\DivisionRepositoryInterface;

/** @implements QueryHandlerInterface<GetDivisionCommand> */
#[AsQueryHandler(GetDivisionCommand::class)]
final class GetDivisionHandler implements QueryHandlerInterface
{
    public function __construct(private readonly DivisionRepositoryInterface $divisions)
    {
    }

    /** @param GetDivisionCommand $command */
    public function __invoke(Query $command): Result
    {
        $division = $this->divisions->findById($command->id) ?? throw new DivisionNotFoundException();

        return Result::success(DivisionTransformer::toArray($division));
    }
}
