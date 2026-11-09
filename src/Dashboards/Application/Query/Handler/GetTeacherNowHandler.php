<?php

declare(strict_types=1);

namespace App\Dashboards\Application\Query\Handler;

use App\Classes\Application\Api\TimetableDirectoryInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Dashboards\Application\Query\Command\GetTeacherNowCommand;
use App\Dashboards\Application\Service\DashboardScope;

/** @implements QueryHandlerInterface<GetTeacherNowCommand> */
#[AsQueryHandler(GetTeacherNowCommand::class)]
final class GetTeacherNowHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly DashboardScope $scope,
        private readonly TimetableDirectoryInterface $timetable,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param GetTeacherNowCommand $command */
    public function __invoke(Query $command): Result
    {
        $teacher = $this->scope->account($command->actorId);
        $schoolYear = $this->scope->currentYear();

        return Result::success([
            'now' => null === $schoolYear ? null : $this->timetable->teacherNow(
                $teacher,
                $schoolYear,
                $this->clock->now(),
            ),
        ]);
    }
}
