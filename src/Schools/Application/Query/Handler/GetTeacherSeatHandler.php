<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Query\Command\GetTeacherSeatCommand;
use App\Schools\Application\Service\SchoolTeacherScope;
use App\Schools\Application\Transformer\SchoolTransformer;
use App\Schools\Domain\Service\Plan\PlanLimits;

/** @implements QueryHandlerInterface<GetTeacherSeatCommand> */
#[AsQueryHandler(GetTeacherSeatCommand::class)]
final class GetTeacherSeatHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly SchoolTeacherScope $scope,
        private readonly PlanLimits $planLimits,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param GetTeacherSeatCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->scope->school($command->schoolId);

        return Result::success([
            'school' => SchoolTransformer::toArray($school, $this->clock->now()),
            'refusal' => $this->planLimits->canAddTeacher($school, $this->scope->currentYear()),
        ]);
    }
}
