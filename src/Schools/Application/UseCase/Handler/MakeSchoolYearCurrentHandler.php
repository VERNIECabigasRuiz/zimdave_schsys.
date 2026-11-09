<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\UseCase\Command\MakeSchoolYearCurrentCommand;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;

/** @implements UseCaseHandlerInterface<MakeSchoolYearCurrentCommand> */
#[AsUseCaseHandler(MakeSchoolYearCurrentCommand::class)]
final class MakeSchoolYearCurrentHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param MakeSchoolYearCurrentCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $schoolYear = $this->schoolYears->findById($command->id) ?? throw new SchoolYearNotFoundException();
        $this->schoolYears->makeCurrent($schoolYear);
        $this->activityLogger->log('Set current school year to ' . $schoolYear->getName());

        return Result::success(['name' => $schoolYear->getName()]);
    }
}
