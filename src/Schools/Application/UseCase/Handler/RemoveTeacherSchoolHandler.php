<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\SchoolTeacherManager;
use App\Schools\Application\Service\SchoolTeacherScope;
use App\Schools\Application\UseCase\Command\RemoveTeacherSchoolCommand;

/** @implements UseCaseHandlerInterface<RemoveTeacherSchoolCommand> */
#[AsUseCaseHandler(RemoveTeacherSchoolCommand::class)]
final class RemoveTeacherSchoolHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolTeacherScope $scope,
        private readonly SchoolTeacherManager $teachers,
    ) {
    }

    /** @param RemoveTeacherSchoolCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $account = $this->scope->teacherAccount($command->accountId);
        $schoolYear = $this->scope->currentYear();
        $this->teachers->removeFromSchool($schoolYear, $account);

        return Result::success(['fullName' => $account->getFullName(), 'schoolYear' => $schoolYear->getName()]);
    }
}
