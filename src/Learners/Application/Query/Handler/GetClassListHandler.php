<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Query\Command\GetClassListCommand;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\Transformer\EnrollmentTransformer;
use App\Learners\Application\Transformer\LearnerPageTransformer;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;

/** @implements QueryHandlerInterface<GetClassListCommand> */
#[AsQueryHandler(GetClassListCommand::class)]
final class GetClassListHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LearnersScope $scope,
        private readonly LearnerPolicy $policy,
        private readonly EnrollmentRepositoryInterface $enrollments,
    ) {
    }

    /** @param GetClassListCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $page = $this->scope->page($command->schoolId, $command->fixedSectionId);
        $section = $this->scope->section($page, $command->sectionId);
        $this->policy->deny($actor, LearnerPolicy::VIEW_CLASS, $section);
        $plan = $page->school->getPlan();

        return Result::success(LearnerPageTransformer::toArray($page) + [
            'section' => EnrollmentTransformer::section($section),
            'enrollments' => array_map(
                static fn (Enrollment $enrollment) => EnrollmentTransformer::toArray($enrollment),
                $this->enrollments->findClassList($section),
            ),
            // without a principal a section holds at most one seat's worth
            'limit' => false === $plan?->includesPrincipal() ? $plan->getStudentsPerSeat() : null,
        ]);
    }
}
