<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\UseCase\Command\GradingPeriodInput;
use App\Schools\Application\UseCase\Command\UpdateSchoolYearCommand;
use App\Schools\Domain\Exception\GradedPeriodRemovalException;
use App\Schools\Domain\Exception\GradingSystemLockedException;
use App\Schools\Domain\Exception\SchoolYearNameTakenException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Domain\Service\SchoolYear\GradingPeriodUsageInterface;

/** @implements UseCaseHandlerInterface<UpdateSchoolYearCommand> */
#[AsUseCaseHandler(UpdateSchoolYearCommand::class)]
final class UpdateSchoolYearHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly GradingPeriodUsageInterface $usage,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param UpdateSchoolYearCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $schoolYear = $this->schoolYears->findById($command->id) ?? throw new SchoolYearNotFoundException();
        $owner = $this->schoolYears->findByName(trim($command->name));
        if (null !== $owner && $owner !== $schoolYear) {
            throw new SchoolYearNameTakenException();
        }

        $originalSystem = $schoolYear->getGradingSystem();
        $hadOpenPeriod = $this->usage->hasOpenPeriod($schoolYear);
        $periodsBefore = $schoolYear->getGradingPeriods()->toArray();

        $schoolYear->setName($command->name)
            ->setStartDate($command->startDate)
            ->setEndDate($command->endDate)
            ->setGradingSystem($command->gradingSystem);
        $this->applyRows($schoolYear, $command->gradingPeriods);

        if ($schoolYear->getGradingSystem() !== $originalSystem) {
            if ($hadOpenPeriod) {
                throw new GradingSystemLockedException();
            }
            if (!$schoolYear->hasMatchingGradingPeriods()) {
                // The page normally redraws the period rows for the new system; without that, split evenly
                $schoolYear->rebuildGradingPeriods();
            }
        }

        // Deleting a period deletes its grades and locks in every school, so a graded period stays
        $graded = $this->firstGradedPeriodRemoved($schoolYear, $periodsBefore);
        if (null !== $graded) {
            throw new GradedPeriodRemovalException(\sprintf(
                '%s has grades. Switching to %ss would delete them.',
                $originalSystem->periodName() . ' ' . $graded->getNumber(),
                strtolower($schoolYear->getGradingSystem()->periodName()),
            ));
        }

        $this->activityLogger->log('Updated school year ' . $schoolYear->getName());

        return Result::success(['name' => $schoolYear->getName()]);
    }

    /**
     * Gives the school year exactly the form's period rows: their dates by number, new rows added,
     * missing ones removed.
     *
     * @param array<int, GradingPeriodInput> $rows
     */
    private function applyRows(SchoolYear $schoolYear, array $rows): void
    {
        $numbers = [];
        foreach ($rows as $row) {
            $period = $schoolYear->getGradingPeriod($row->number);
            if (null === $period) {
                $period = new GradingPeriod($schoolYear, $row->number);
                $schoolYear->addGradingPeriod($period);
            }
            $period->setStartDate($row->startDate)->setEndDate($row->endDate);
            $numbers[] = $row->number;
        }
        foreach ($schoolYear->getGradingPeriods()->toArray() as $period) {
            if (!\in_array($period->getNumber(), $numbers, true)) {
                $schoolYear->removeGradingPeriod($period);
            }
        }
    }

    /**
     * The first period, by number, that the edit takes away although grades were entered or locked in it.
     *
     * @param array<int, GradingPeriod> $before the periods before the edit
     */
    private function firstGradedPeriodRemoved(SchoolYear $schoolYear, array $before): ?GradingPeriod
    {
        $periods = $schoolYear->getGradingPeriods();
        $removed = array_filter($before, static fn (GradingPeriod $period) => !$periods->contains($period));
        usort($removed, static fn (GradingPeriod $a, GradingPeriod $b) => $a->getNumber() <=> $b->getNumber());
        foreach ($removed as $period) {
            if ($this->usage->isGraded($period)) {
                return $period;
            }
        }

        return null;
    }
}
