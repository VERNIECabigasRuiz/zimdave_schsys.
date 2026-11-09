<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Staff\Application\Service\ServiceRecordKeeper;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Application\UseCase\Command\SaveServiceRowCommand;

/** @implements UseCaseHandlerInterface<SaveServiceRowCommand> */
#[AsUseCaseHandler(SaveServiceRowCommand::class)]
final class SaveServiceRowHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly ServiceRecordKeeper $keeper,
    ) {
    }

    /** @param SaveServiceRowCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $employee = $this->scope->schoolEmployee($this->scope->school($command->schoolId), $command->employeeId);
        $entry = null === $command->entryId ? null : $this->scope->serviceRow($employee, $command->entryId);
        $this->keeper->save($command, $employee, $entry, $actor);

        return Result::success(null);
    }
}
