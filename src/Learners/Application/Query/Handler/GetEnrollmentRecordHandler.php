<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Query\Command\GetEnrollmentRecordCommand;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\Transformer\EnrollmentTransformer;
use App\Learners\Application\Transformer\LearnerPageTransformer;
use App\Learners\Application\Transformer\LearnerTransformer;
use App\Learners\Domain\Service\Sf1\Sf1Remarks;

/** @implements QueryHandlerInterface<GetEnrollmentRecordCommand> */
#[AsQueryHandler(GetEnrollmentRecordCommand::class)]
final class GetEnrollmentRecordHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LearnersScope $scope,
        private readonly LearnerPolicy $policy,
        private readonly Sf1Remarks $remarks,
    ) {
    }

    /** @param GetEnrollmentRecordCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $page = $this->scope->page($command->schoolId, $command->fixedSectionId);
        [$learner, $current] = $this->scope->enrolledLearner($page, $command->learnerId, $actor);
        $this->policy->deny($actor, LearnerPolicy::EDIT, $learner);

        return Result::success(LearnerPageTransformer::toArray($page) + [
            'learner' => LearnerTransformer::toArray($learner),
            'enrollment' => EnrollmentTransformer::toArray($current),
            'automaticRemark' => $this->remarks->automaticFor($current),
        ]);
    }
}
