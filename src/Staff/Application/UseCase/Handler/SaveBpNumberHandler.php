<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Staff\Application\Service\ServiceRecordPrinter;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Application\UseCase\Command\SaveBpNumberCommand;
use App\Staff\Domain\Model\Repository\ServiceRecordRepositoryInterface;

/** @implements UseCaseHandlerInterface<SaveBpNumberCommand> */
#[AsUseCaseHandler(SaveBpNumberCommand::class)]
final class SaveBpNumberHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly ServiceRecordPrinter $printer,
        private readonly ServiceRecordRepositoryInterface $records,
    ) {
    }

    /** @param SaveBpNumberCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $employee = $this->scope->schoolEmployee($this->scope->school($command->schoolId), $command->employeeId);
        $record = $this->printer->recordOf($employee);
        $record->setBpNumber(mb_substr($command->bpNumber, 0, 30));
        $this->records->add($record);

        return Result::success(null);
    }
}
