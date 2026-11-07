<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\SchoolTeacherManager;
use App\Schools\Application\Service\SchoolTeacherScope;
use App\Schools\Application\UseCase\Command\ResetTeacherPasswordCommand;

/** @implements UseCaseHandlerInterface<ResetTeacherPasswordCommand> */
#[AsUseCaseHandler(ResetTeacherPasswordCommand::class)]
final class ResetTeacherPasswordHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolTeacherScope $scope,
        private readonly SchoolTeacherManager $teachers,
    ) {
    }

    /** @param ResetTeacherPasswordCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $teacher = $this->scope->teacher($school, $this->scope->currentYear(), $command->teacherId);
        $password = $this->teachers->resetPassword($teacher);

        return Result::success([
            'fullName' => $teacher->getFullName(),
            'username' => $teacher->getUsername(),
            'password' => $password,
        ]);
    }
}
