<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Query\Command\GetEnrollPageCommand;
use App\Learners\Application\Service\LearnerLookup;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\Transformer\EnrollmentTransformer;
use App\Learners\Application\Transformer\LearnerPageTransformer;
use App\Learners\Application\Transformer\LearnerTransformer;

/** @implements QueryHandlerInterface<GetEnrollPageCommand> */
#[AsQueryHandler(GetEnrollPageCommand::class)]
final class GetEnrollPageHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LearnersScope $scope,
        private readonly LearnerPolicy $policy,
        private readonly LearnerLookup $lookup,
    ) {
    }

    /** @param GetEnrollPageCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $page = $this->scope->page($command->schoolId, $command->fixedSectionId);
        if (null !== $page->fixedSection) {
            $this->policy->deny($actor, LearnerPolicy::ENROLL, $page->fixedSection);
        }
        $data = LearnerPageTransformer::toArray($page);
        if (null === $command->lrn) {
            return Result::success($data + ['lookup' => null]);
        }

        $result = $this->lookup->find($command->lrn, $page->school, $page->schoolYear);

        return Result::success($data + ['lookup' => [
            'kind' => $result->kind,
            'learner' => null !== $result->learner ? LearnerTransformer::toArray($result->learner) : null,
            'enrollment' => null !== $result->enrollment ? EnrollmentTransformer::toArray($result->enrollment) : null,
            // the adviser's own section is fixed: no section picker
            'sections' => null === $page->fixedSection
                ? array_map(EnrollmentTransformer::section(...), $this->scope->sections($page))
                : null,
            'canRequestTransfer' => $this->policy->can($actor, LearnerPolicy::REQUEST_TRANSFER, $page->school),
        ]]);
    }
}
