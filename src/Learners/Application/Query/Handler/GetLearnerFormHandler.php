<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Query\Command\GetLearnerFormCommand;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\Transformer\LearnerPageTransformer;
use App\Learners\Application\Transformer\LearnerTransformer;

/** @implements QueryHandlerInterface<GetLearnerFormCommand> */
#[AsQueryHandler(GetLearnerFormCommand::class)]
final class GetLearnerFormHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LearnersScope $scope,
        private readonly LearnerPolicy $policy,
    ) {
    }

    /** @param GetLearnerFormCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $page = $this->scope->page($command->schoolId, $command->fixedSectionId);
        [$learner] = $this->scope->learner($page, $command->learnerId, $actor, true);
        $this->policy->deny($actor, LearnerPolicy::EDIT, $learner);

        return Result::success(LearnerPageTransformer::toArray($page) + [
            'learner' => LearnerTransformer::toArray($learner),
            'withLrn' => $this->policy->can($actor, LearnerPolicy::CHANGE_LRN, $learner),
        ]);
    }
}
