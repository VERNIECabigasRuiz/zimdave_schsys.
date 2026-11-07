<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\SchoolTeacherScope;
use App\Schools\Application\UseCase\Command\UpdateTeacherCommand;

/** @implements UseCaseHandlerInterface<UpdateTeacherCommand> */
#[AsUseCaseHandler(UpdateTeacherCommand::class)]
final class UpdateTeacherHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolTeacherScope $scope,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param UpdateTeacherCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $teacher = $this->scope->teacher($school, $this->scope->currentYear(), $command->teacherId);
        $teacher->setFullName($command->fullName)->setUsername($command->username);
        $this->activityLogger->log('Updated teacher ' . $teacher->getUsername());

        return Result::success(['fullName' => $teacher->getFullName()]);
    }
}
