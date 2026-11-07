<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\SchoolDetailsWriter;
use App\Schools\Application\UseCase\Command\ToggleSchoolActiveCommand;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;

/** @implements UseCaseHandlerInterface<ToggleSchoolActiveCommand> */
#[AsUseCaseHandler(ToggleSchoolActiveCommand::class)]
final class ToggleSchoolActiveHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param ToggleSchoolActiveCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $school = $this->schools->findById($command->id) ?? throw new SchoolNotFoundException();
        $school->setActive(!$school->isActive());
        $this->activityLogger->log(\sprintf(
            '%s school %s',
            $school->isActive() ? 'Activated' : 'Deactivated',
            SchoolDetailsWriter::describe($school),
        ));

        return Result::success(['name' => $school->getName(), 'active' => $school->isActive()]);
    }
}
