<?php

declare(strict_types=1);

namespace App\Staff\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Staff\Application\Query\Command\GetPdsPdfCommand;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Domain\Service\Pds\PdsPdfInterface;

/** @implements QueryHandlerInterface<GetPdsPdfCommand> */
#[AsQueryHandler(GetPdsPdfCommand::class)]
final class GetPdsPdfHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly PdsPdfInterface $pdf,
    ) {
    }

    /** @param GetPdsPdfCommand $command */
    public function __invoke(Query $command): Result
    {
        $sheet = $this->scope->sheetOf($this->scope->account($command->actorId));

        return Result::success(['content' => $this->pdf->render($sheet), 'fileName' => $this->pdf->fileName($sheet)]);
    }
}
