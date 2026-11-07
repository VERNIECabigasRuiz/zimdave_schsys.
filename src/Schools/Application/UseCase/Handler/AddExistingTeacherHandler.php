<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\SchoolTeacherManager;
use App\Schools\Application\Service\SchoolTeacherScope;
use App\Schools\Application\UseCase\Command\AddExistingTeacherCommand;
use App\Schools\Domain\Exception\AssignmentException;

/** @implements UseCaseHandlerInterface<AddExistingTeacherCommand> */
#[AsUseCaseHandler(AddExistingTeacherCommand::class)]
final class AddExistingTeacherHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolTeacherScope $scope,
        private readonly SchoolTeacherManager $teachers,
        private readonly AccountDirectoryInterface $accounts,
    ) {
    }

    /** @param AddExistingTeacherCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->scope->lock($command->schoolId);
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->scope->currentYear();
        $teacher = $this->accounts->findOneByUsername($command->username)
            ?? throw new AssignmentException('No teacher account with that username.');
        $this->teachers->addExisting($school, $schoolYear, $teacher);

        return Result::success(['fullName' => $teacher->getFullName(), 'school' => $school->getName()]);
    }
}
