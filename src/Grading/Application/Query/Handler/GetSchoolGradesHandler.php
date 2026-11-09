<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Grading\Application\Query\Command\GetSchoolGradesCommand;
use App\Grading\Application\Service\ClassProgress;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Application\Service\SchoolGradeOverview;
use App\Grading\Domain\Model\Repository\GradeChangeRequestRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeLockRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeOpeningRepositoryInterface;

/** @implements QueryHandlerInterface<GetSchoolGradesCommand> */
#[AsQueryHandler(GetSchoolGradesCommand::class)]
final class GetSchoolGradesHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly SchoolGradeOverview $overview,
        private readonly GradeLockRepositoryInterface $locks,
        private readonly GradeOpeningRepositoryInterface $openings,
        private readonly GradeChangeRequestRepositoryInterface $changeRequests,
    ) {
    }

    /** @param GetSchoolGradesCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->scope->currentYear();
        $selected = $this->scope->selectPeriod($school, $schoolYear, $command->periodId);
        $classes = null === $selected ? [] : $this->overview->build($school, $schoolYear, $selected);

        return Result::success([
            'school' => $school,
            'school_year' => $schoolYear,
            'periods' => $schoolYear->getGradingPeriods(),
            'period' => $selected,
            'lock' => null === $selected ? null : $this->locks->findFor($school, $selected),
            'opening' => null === $selected ? null : $this->openings->findFor($school, $selected),
            'classes' => $classes,
            'complete' => \count(array_filter($classes, static fn (ClassProgress $c) => $c->isComplete())),
            'can_lock' => $this->policy->can($actor, GradePolicy::LOCK, $school),
            'can_open' => $this->policy->can($actor, GradePolicy::OPEN, $school),
            'pending_changes' => $this->changeRequests->countPendingForSchool($school, $schoolYear),
        ]);
    }
}
