<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Query\Command\GetAdvisoryCommand;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\Service\LearnersScope;

/** @implements QueryHandlerInterface<GetAdvisoryCommand> */
#[AsQueryHandler(GetAdvisoryCommand::class)]
final class GetAdvisoryHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LearnersScope $scope,
        private readonly LearnerPolicy $policy,
    ) {
    }

    /** @param GetAdvisoryCommand $command */
    public function __invoke(Query $command): Result
    {
        $teacher = $this->scope->account($command->actorId);
        $section = $this->scope->advisory($teacher);
        $this->policy->deny($teacher, LearnerPolicy::VIEW_CLASS, $section);

        return Result::success(
            ['schoolId' => (int) $section->getSchool()->getId(), 'sectionId' => (int) $section->getId()],
        );
    }
}
