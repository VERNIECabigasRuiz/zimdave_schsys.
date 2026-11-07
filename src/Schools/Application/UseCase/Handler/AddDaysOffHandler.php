<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\UseCase\Command\AddDaysOffCommand;
use App\Schools\Domain\Exception\DayOffRangeException;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolDayOffRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Domain\Service\Calendar\DayOffAttendanceInterface;

/**
 * Marks every weekday from "from" to "to" (or "from" alone) as a day off this school year,
 * skipping days already off.
 *
 * @implements UseCaseHandlerInterface<AddDaysOffCommand>
 */
#[AsUseCaseHandler(AddDaysOffCommand::class)]
final class AddDaysOffHandler implements UseCaseHandlerInterface
{
    public const MAX_RANGE_DAYS = 62;

    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolDayOffRepositoryInterface $daysOff,
        private readonly AccountDirectoryInterface $accounts,
        private readonly DayOffAttendanceInterface $attendance,
        private readonly ActivityLoggerInterface $activityLogger,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param AddDaysOffCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $school = $this->schools->findById($command->schoolId) ?? throw new SchoolNotFoundException();
        $schoolYear = $this->schoolYears->findCurrent() ?? throw new SchoolYearNotFoundException();
        $by = $this->accounts->findById($command->userId) ?? throw new \InvalidArgumentException('Unknown account.');
        $from = ($command->from ?? throw new \InvalidArgumentException('No date.'))->setTime(0, 0);
        $to = ($command->to ?? $from)->setTime(0, 0);
        $reason = (string) $command->reason;
        $this->assertAllowed($schoolYear, $from, $to);

        $existing = $this->daysOff->findBetween($school, $from, $to);
        $now = $this->clock->now();
        $added = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') <= 5 && !isset($existing[$day->format('Y-m-d')])) {
                $this->daysOff->add(new SchoolDayOff($school, $schoolYear, $day, $reason, $by, $now));
                $added[] = $day;
            }
        }
        if ([] === $added) {
            return Result::success(['added' => 0, 'sectionsWithAttendance' => 0]);
        }

        $this->activityLogger->log(\sprintf(
            'Added day off %s%s: %s',
            $from->format('Y-m-d'),
            $to > $from ? ' to ' . $to->format('Y-m-d') : '',
            trim($reason),
        ));

        return Result::success([
            'added' => \count($added),
            'sectionsWithAttendance' => $this->attendance->countSectionsWithAttendanceOn($school, $added),
        ]);
    }

    private function assertAllowed(SchoolYear $schoolYear, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $start = $schoolYear->getStartDate()?->setTime(0, 0);
        $end = $schoolYear->getEndDate()?->setTime(0, 0);
        if ($to < $from) {
            throw new DayOffRangeException('The end date must be on or after the start date.');
        }
        if ((null !== $start && $from < $start) || (null !== $end && $to > $end)) {
            throw new DayOffRangeException(\sprintf(
                'Days off must be inside the school year (%s – %s).',
                $start?->format('M j, Y'),
                $end?->format('M j, Y'),
            ));
        }
        if ($from->diff($to)->days >= self::MAX_RANGE_DAYS) {
            throw new DayOffRangeException(\sprintf('Add at most %d days at a time.', self::MAX_RANGE_DAYS));
        }
    }
}
