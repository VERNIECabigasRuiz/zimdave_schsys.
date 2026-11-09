<?php

declare(strict_types=1);

namespace App\Dashboards\Application\Query\Handler;

use App\Access\Domain\Model\User;
use App\Classes\Application\Api\TimetableDirectoryInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Dashboards\Application\Query\Command\GetPrincipalNowCommand;
use App\Dashboards\Application\Service\DashboardScope;

/** @implements QueryHandlerInterface<GetPrincipalNowCommand> */
#[AsQueryHandler(GetPrincipalNowCommand::class)]
final class GetPrincipalNowHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly DashboardScope $scope,
        private readonly TimetableDirectoryInterface $timetable,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param GetPrincipalNowCommand $command */
    public function __invoke(Query $command): Result
    {
        $schoolYear = $this->scope->currentYear();
        $school = $this->scope->schoolOf($this->scope->account($command->actorId), $schoolYear, User::ROLE_PRINCIPAL);

        return Result::success([
            'now' => null === $school || null === $schoolYear
                ? null
                : $this->timetable->schoolNow($school, $schoolYear, $this->clock->now()),
        ]);
    }
}
