<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Grading\Application\Query\Command\GetGradeChangesCommand;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradeRecorder;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Domain\Model\Repository\GradeChangeRequestRepositoryInterface;

/** @implements QueryHandlerInterface<GetGradeChangesCommand> */
#[AsQueryHandler(GetGradeChangesCommand::class)]
final class GetGradeChangesHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly GradeChangeRequestRepositoryInterface $requests,
    ) {
    }

    /** @param GetGradeChangesCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->scope->currentYear();
        $decided = 'decided' === $command->status;
        $requests = $decided
            ? $this->requests->findDecidedForSchool($school, $schoolYear)
            : $this->requests->findPendingForSchool($school, $schoolYear);

        $labels = [];
        $decidable = [];
        foreach ($requests as $request) {
            $id = (int) $request->getId();
            $labels[$id] = GradeRecorder::classLabel($request->getSection(), $request->getSubject());
            if ($request->isPending() && $this->policy->can($actor, GradePolicy::CHANGE_DECIDE, $request)) {
                $decidable[] = $id;
            }
        }

        return Result::success([
            'school' => $school,
            'school_year' => $schoolYear,
            'status' => $decided ? 'decided' : 'pending',
            'requests' => $requests,
            'class_labels' => $labels,
            'decidable' => $decidable,
            'pending_count' => $decided
                ? $this->requests->countPendingForSchool($school, $schoolYear)
                : \count($requests),
        ]);
    }
}
