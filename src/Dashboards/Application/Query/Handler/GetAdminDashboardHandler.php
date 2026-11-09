<?php

declare(strict_types=1);

namespace App\Dashboards\Application\Query\Handler;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Dashboards\Application\Query\Command\GetAdminDashboardCommand;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** @implements QueryHandlerInterface<GetAdminDashboardCommand> */
#[AsQueryHandler(GetAdminDashboardCommand::class)]
final class GetAdminDashboardHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly SchoolDirectoryInterface $schools,
        private readonly AccountDirectoryInterface $accounts,
    ) {
    }

    /** @param GetAdminDashboardCommand $command */
    public function __invoke(Query $command): Result
    {
        $schoolYear = $this->schools->currentYear();

        return Result::success([
            'plans_expiring' => $this->schools->expiringPlansBy($command->expiringBy),
            'school_year' => $schoolYear,
            'year_stats' => null !== $schoolYear ? $this->schools->yearOverview($schoolYear) : null,
            'stats' => $this->accounts->accountCounts(),
        ]);
    }
}
