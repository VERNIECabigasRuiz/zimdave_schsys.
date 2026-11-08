<?php

declare(strict_types=1);

namespace App\Staff\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Staff\Application\Query\Command\GetPersonalDataSheetCommand;
use App\Staff\Application\Service\StaffScope;

/** @implements QueryHandlerInterface<GetPersonalDataSheetCommand> */
#[AsQueryHandler(GetPersonalDataSheetCommand::class)]
final class GetPersonalDataSheetHandler implements QueryHandlerInterface
{
    public function __construct(private readonly StaffScope $scope)
    {
    }

    /** @param GetPersonalDataSheetCommand $command */
    public function __invoke(Query $command): Result
    {
        return Result::success(['sheet' => $this->scope->sheetOf($this->scope->account($command->actorId))]);
    }
}
