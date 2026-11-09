<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Query\Command\GetTransferRequestFormCommand;
use App\Learners\Application\Service\LearnerLookup;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\Service\LearnerScope;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\Service\LookupKind;
use App\Learners\Application\Transformer\EnrollmentTransformer;

/** @implements QueryHandlerInterface<GetTransferRequestFormCommand> */
#[AsQueryHandler(GetTransferRequestFormCommand::class)]
final class GetTransferRequestFormHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LearnersScope $scope,
        private readonly LearnerPolicy $policy,
        private readonly LearnerLookup $lookup,
    ) {
    }

    /**
     * Null as the learner when the LRN isn't a learner enrolled at another school (any more).
     *
     * @param GetTransferRequestFormCommand $command
     */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $school = $this->scope->school($command->schoolId);
        $this->policy->deny($actor, LearnerPolicy::REQUEST_TRANSFER, $school);
        $page = new LearnerScope($school, $this->scope->currentYear());
        $found = $this->lookup->find($command->lrn, $school, $page->schoolYear);
        $elsewhere = LookupKind::Elsewhere === $found->kind ? $found->enrollment : null;

        return Result::success([
            'learnerId' => $elsewhere?->getLearner()->getId(),
            'sections' => array_map(EnrollmentTransformer::section(...), $this->scope->sections($page)),
        ]);
    }
}
