<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Handler;

use App\Classes\Application\Api\TeachingLoadDirectoryInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Grading\Application\Query\Command\GetGradeChangeCommand;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradingScope;

/** @implements QueryHandlerInterface<GetGradeChangeCommand> */
#[AsQueryHandler(GetGradeChangeCommand::class)]
final class GetGradeChangeHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly TeachingLoadDirectoryInterface $loads,
    ) {
    }

    /** @param GetGradeChangeCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $school = null === $command->schoolId ? null : $this->scope->school($command->schoolId);
        $request = $this->scope->changeRequest($command->requestId, $school);
        $this->policy->deny($actor, $command->attribute, $request);

        $section = $request->getSection();
        // the class's sheet, while the account may still view it (it may have moved to another teacher)
        $load = $this->loads->loadOf($section, $request->getSubject());
        $sheet = null !== $load && $this->policy->can($actor, GradePolicy::VIEW, $load)
            ? ['load' => (int) $load->getId(), 'period' => (int) $request->getGradingPeriod()->getId()]
            : null;

        return Result::success([
            'request' => $request,
            'pending' => $request->isPending(),
            'school_id' => (int) $section->getSchool()->getId(),
            'section_id' => (int) $section->getId(),
            'subject_id' => (int) $request->getSubject()->getId(),
            'sheet' => $sheet,
        ]);
    }
}
