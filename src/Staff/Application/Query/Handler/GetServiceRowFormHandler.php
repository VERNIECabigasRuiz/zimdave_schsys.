<?php

declare(strict_types=1);

namespace App\Staff\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Staff\Application\Query\Command\GetServiceRowFormCommand;
use App\Staff\Application\Service\ServiceRecordKeeper;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Application\UseCase\Command\SaveServiceRowCommand;
use App\Staff\Domain\Model\Repository\ServiceRecordEntryRepositoryInterface;

/** @implements QueryHandlerInterface<GetServiceRowFormCommand> */
#[AsQueryHandler(GetServiceRowFormCommand::class)]
final class GetServiceRowFormHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly ServiceRecordKeeper $keeper,
        private readonly ServiceRecordEntryRepositoryInterface $entries,
    ) {
    }

    /** @param GetServiceRowFormCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $employee = $this->scope->schoolEmployee($school, $command->employeeId);
        if (null !== $command->entryId) {
            $entry = $this->scope->serviceRow($employee, $command->entryId);
            $row = SaveServiceRowCommand::fromEntry($entry, $command->actorId, $command->schoolId);

            return Result::success(
                ['school' => $school, 'employee' => $employee, 'row' => $row, 'canCloseOpen' => false],
            );
        }

        $row = $this->keeper->draft($employee, $school);
        $row->actorId = $command->actorId;
        $row->schoolId = $command->schoolId;
        // adding while a row runs to the present offers to close it, ticked
        $row->closeOpen = null !== $this->entries->findCurrent($employee);

        return Result::success([
            'school' => $school,
            'employee' => $employee,
            'row' => $row,
            'canCloseOpen' => $row->closeOpen,
        ]);
    }
}
