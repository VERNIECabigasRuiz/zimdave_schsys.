<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\SchoolTeacherManager;
use App\Schools\Application\Service\SchoolTeacherScope;
use App\Schools\Application\UseCase\Command\AssignTeacherSchoolCommand;

/** @implements UseCaseHandlerInterface<AssignTeacherSchoolCommand> */
#[AsUseCaseHandler(AssignTeacherSchoolCommand::class)]
final class AssignTeacherSchoolHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolTeacherScope $scope,
        private readonly SchoolTeacherManager $teachers,
    ) {
    }

    /** @param AssignTeacherSchoolCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->scope->lock((int) $command->schoolId);
        $account = $this->scope->teacherAccount($command->accountId);
        $schoolYear = $this->scope->currentYear();
        $school = $this->scope->school((int) $command->schoolId);
        $this->teachers->assignToSchool($school, $schoolYear, $account);

        return Result::success([
            'fullName' => $account->getFullName(),
            'school' => $school->getName(),
            'schoolYear' => $schoolYear->getName(),
        ]);
    }
}
