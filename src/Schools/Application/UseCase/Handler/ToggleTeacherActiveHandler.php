<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\SchoolTeacherManager;
use App\Schools\Application\Service\SchoolTeacherScope;
use App\Schools\Application\UseCase\Command\ToggleTeacherActiveCommand;

/** @implements UseCaseHandlerInterface<ToggleTeacherActiveCommand> */
#[AsUseCaseHandler(ToggleTeacherActiveCommand::class)]
final class ToggleTeacherActiveHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolTeacherScope $scope,
        private readonly SchoolTeacherManager $teachers,
    ) {
    }

    /** @param ToggleTeacherActiveCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->scope->lock($command->schoolId);
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->scope->currentYear();
        $teacher = $this->scope->teacher($school, $schoolYear, $command->teacherId);
        if ($teacher->isActive()) {
            $this->teachers->deactivate($teacher);
        } else {
            $this->teachers->activate($school, $schoolYear, $teacher);
        }

        return Result::success(['fullName' => $teacher->getFullName(), 'active' => $teacher->isActive()]);
    }
}
