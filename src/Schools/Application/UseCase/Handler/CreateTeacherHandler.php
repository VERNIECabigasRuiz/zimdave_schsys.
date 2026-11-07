<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\SchoolTeacherManager;
use App\Schools\Application\Service\SchoolTeacherScope;
use App\Schools\Application\UseCase\Command\CreateTeacherCommand;

/** @implements UseCaseHandlerInterface<CreateTeacherCommand> */
#[AsUseCaseHandler(CreateTeacherCommand::class)]
final class CreateTeacherHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolTeacherScope $scope,
        private readonly SchoolTeacherManager $teachers,
    ) {
    }

    /** @param CreateTeacherCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->scope->lock($command->schoolId);
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->scope->currentYear();

        $teacher = (new User())->setFullName($command->fullName)->setUsername($command->username);
        $password = $this->teachers->create($school, $schoolYear, $teacher);

        return Result::success([
            'fullName' => $teacher->getFullName(),
            'username' => $teacher->getUsername(),
            'password' => $password,
        ]);
    }
}
