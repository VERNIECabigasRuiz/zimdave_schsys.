<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Application\UseCase\Command\DeleteServiceRowCommand;
use App\Staff\Domain\Model\Repository\ServiceRecordEntryRepositoryInterface;

/** @implements UseCaseHandlerInterface<DeleteServiceRowCommand> */
#[AsUseCaseHandler(DeleteServiceRowCommand::class)]
final class DeleteServiceRowHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly ServiceRecordEntryRepositoryInterface $entries,
    ) {
    }

    /** @param DeleteServiceRowCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $employee = $this->scope->schoolEmployee($this->scope->school($command->schoolId), $command->employeeId);
        $this->entries->remove($this->scope->serviceRow($employee, $command->entryId));

        return Result::success(null);
    }
}
