<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\SchoolTeacherManager;
use App\Schools\Application\Service\SchoolTeacherScope;
use App\Schools\Application\UseCase\Command\BringBackTeachersCommand;

/** @implements UseCaseHandlerInterface<BringBackTeachersCommand> */
#[AsUseCaseHandler(BringBackTeachersCommand::class)]
final class BringBackTeachersHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolTeacherScope $scope,
        private readonly SchoolTeacherManager $teachers,
    ) {
    }

    /** @param BringBackTeachersCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->scope->lock($command->schoolId);
        $school = $this->scope->school($command->schoolId);
        $back = $this->teachers->bringBack($school, $this->scope->currentYear());

        return Result::success(['added' => $back->added, 'skipped' => $back->skipped]);
    }
}
