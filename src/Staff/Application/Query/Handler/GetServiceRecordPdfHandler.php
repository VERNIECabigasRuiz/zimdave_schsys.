<?php

declare(strict_types=1);

namespace App\Staff\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Staff\Application\Query\Command\GetServiceRecordPdfCommand;
use App\Staff\Application\Service\ServiceRecordPrinter;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Domain\Service\ServiceRecord\ServiceRecordPdfInterface;

/** @implements QueryHandlerInterface<GetServiceRecordPdfCommand> */
#[AsQueryHandler(GetServiceRecordPdfCommand::class)]
final class GetServiceRecordPdfHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly ServiceRecordPrinter $printer,
        private readonly ServiceRecordPdfInterface $pdf,
    ) {
    }

    /** @param GetServiceRecordPdfCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $printout = $this->printer->printout($this->scope->schoolEmployee($school, $command->employeeId), $school);

        return Result::success(['content' => $this->pdf->render($printout), 'fileName' => $printout->fileName()]);
    }
}
