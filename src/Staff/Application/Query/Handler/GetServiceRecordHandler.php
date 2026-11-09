<?php

declare(strict_types=1);

namespace App\Staff\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Staff\Application\Query\Command\GetServiceRecordCommand;
use App\Staff\Application\Service\ServiceRecordPrinter;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Domain\Model\Repository\PersonalDataSheetRepositoryInterface;
use App\Staff\Domain\Model\Repository\ServiceRecordEntryRepositoryInterface;

/** @implements QueryHandlerInterface<GetServiceRecordCommand> */
#[AsQueryHandler(GetServiceRecordCommand::class)]
final class GetServiceRecordHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly ServiceRecordPrinter $printer,
        private readonly PersonalDataSheetRepositoryInterface $sheets,
        private readonly ServiceRecordEntryRepositoryInterface $entries,
    ) {
    }

    /** @param GetServiceRecordCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $employee = $this->scope->schoolEmployee($school, $command->employeeId);
        $sheet = $this->sheets->findFor($employee);

        return Result::success([
            'school' => $school,
            'employee' => $employee,
            'sheet' => $sheet,
            'missing' => $this->printer->missing($sheet),
            'record' => $this->printer->recordOf($employee),
            'rows' => $this->entries->findForEmployee($employee),
        ]);
    }
}
