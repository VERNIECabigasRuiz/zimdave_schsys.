<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\UseCase\Command\CreateSchoolYearCommand;
use App\Schools\Domain\Exception\SchoolYearNameTakenException;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\SchoolYear;

/** @implements UseCaseHandlerInterface<CreateSchoolYearCommand> */
#[AsUseCaseHandler(CreateSchoolYearCommand::class)]
final class CreateSchoolYearHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param CreateSchoolYearCommand $command */
    public function __invoke(UseCase $command): Result
    {
        if (null !== $this->schoolYears->findByName(trim($command->name))) {
            throw new SchoolYearNameTakenException();
        }

        $schoolYear = (new SchoolYear())
            ->setName($command->name)
            ->setStartDate($command->startDate)
            ->setEndDate($command->endDate)
            ->setGradingSystem($command->gradingSystem);
        // The first school year ever created becomes the current one
        if (null === $this->schoolYears->findCurrent()) {
            $schoolYear->setCurrent(true);
        }
        $schoolYear->rebuildGradingPeriods();
        $this->schoolYears->add($schoolYear);
        $this->activityLogger->log('Created school year ' . $schoolYear->getName());

        return Result::success([
            'name' => $schoolYear->getName(),
            'periodCount' => $schoolYear->getGradingSystem()->periodCount(),
            'periodName' => $schoolYear->getGradingSystem()->periodName(),
        ]);
    }
}
