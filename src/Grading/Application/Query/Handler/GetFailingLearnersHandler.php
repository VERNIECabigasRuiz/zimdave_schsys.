<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Grading\Application\Query\Command\GetFailingLearnersCommand;
use App\Grading\Application\Service\FailingLearners;
use App\Grading\Application\Service\GradingScope;

/** @implements QueryHandlerInterface<GetFailingLearnersCommand> */
#[AsQueryHandler(GetFailingLearnersCommand::class)]
final class GetFailingLearnersHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly GradingScope $scope,
        private readonly FailingLearners $failingLearners,
    ) {
    }

    /** @param GetFailingLearnersCommand $command */
    public function __invoke(Query $command): Result
    {
        $this->scope->account($command->actorId);
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->scope->currentYear();
        $selected = $this->scope->selectPeriod($school, $schoolYear, $command->periodId);

        return Result::success([
            'school' => $school,
            'school_year' => $schoolYear,
            'periods' => $schoolYear->getGradingPeriods(),
            'period' => $selected,
            'learners' => null === $selected ? [] : $this->failingLearners->forSchool($school, $schoolYear, $selected),
        ]);
    }
}
