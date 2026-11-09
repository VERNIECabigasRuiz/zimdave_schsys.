<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Grading\Application\Query\Command\GetGradeLockCommand;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradingScope;

/** @implements QueryHandlerInterface<GetGradeLockCommand> */
#[AsQueryHandler(GetGradeLockCommand::class)]
final class GetGradeLockHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
    ) {
    }

    /** @param GetGradeLockCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $school = $this->scope->school($command->schoolId);
        $this->policy->deny($actor, GradePolicy::LOCK, $school);

        return Result::success(['period' => $this->scope->currentPeriod($command->periodId)]);
    }
}
