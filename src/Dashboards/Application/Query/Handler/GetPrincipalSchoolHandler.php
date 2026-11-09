<?php

declare(strict_types=1);

namespace App\Dashboards\Application\Query\Handler;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Dashboards\Application\Query\Command\GetPrincipalSchoolCommand;
use App\Dashboards\Application\Service\DashboardScope;

/** @implements QueryHandlerInterface<GetPrincipalSchoolCommand> */
#[AsQueryHandler(GetPrincipalSchoolCommand::class)]
final class GetPrincipalSchoolHandler implements QueryHandlerInterface
{
    public function __construct(private readonly DashboardScope $scope)
    {
    }

    /** @param GetPrincipalSchoolCommand $command */
    public function __invoke(Query $command): Result
    {
        $schoolYear = $this->scope->currentYear();
        $school = $this->scope->schoolOf($this->scope->account($command->actorId), $schoolYear, User::ROLE_PRINCIPAL);

        return Result::success(['schoolId' => $school?->getId(), 'school_year' => $schoolYear]);
    }
}
