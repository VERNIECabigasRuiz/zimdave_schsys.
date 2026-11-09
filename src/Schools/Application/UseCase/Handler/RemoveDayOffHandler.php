<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\UseCase\Command\RemoveDayOffCommand;
use App\Schools\Domain\Exception\DayOffNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolDayOffRepositoryInterface;

/** @implements UseCaseHandlerInterface<RemoveDayOffCommand> */
#[AsUseCaseHandler(RemoveDayOffCommand::class)]
final class RemoveDayOffHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolDayOffRepositoryInterface $daysOff,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param RemoveDayOffCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $dayOff = $this->daysOff->findById($command->dayOffId);
        if (null === $dayOff || $dayOff->getSchool()->getId() !== $command->schoolId) {
            throw new DayOffNotFoundException();
        }

        $this->activityLogger->log(\sprintf(
            'Removed day off %s (%s)',
            $dayOff->getDate()->format('Y-m-d'),
            $dayOff->getReason(),
        ));
        $this->daysOff->remove($dayOff);

        return Result::success(['date' => $dayOff->getDate()]);
    }
}
