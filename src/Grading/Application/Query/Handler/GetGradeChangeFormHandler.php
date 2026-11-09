<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Grading\Application\Query\Command\GetGradeChangeFormCommand;
use App\Grading\Application\Service\GradeChangeManager;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradeRules;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;

/** @implements QueryHandlerInterface<GetGradeChangeFormCommand> */
#[AsQueryHandler(GetGradeChangeFormCommand::class)]
final class GetGradeChangeFormHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly GradeRules $rules,
        private readonly GradeRepositoryInterface $grades,
        private readonly GradeChangeManager $manager,
    ) {
    }

    /** @param GetGradeChangeFormCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        [$load, $period] = $this->scope->loadAndPeriod($command->loadId, $command->periodId);
        $this->policy->deny($actor, GradePolicy::EDIT, $load);

        $section = $load->getSection();
        $learners = $this->rules->sheet($section);

        return Result::success([
            'load' => $load,
            'period' => $period,
            'refusal' => $this->manager->refusalToRequest($load, $period),
            'learners' => $learners,
            'saved' => $this->grades->findForEnrollments(
                GradeRules::enrollmentIds($learners),
                $load->getSubject(),
                $period,
            ),
            'school_id' => (int) $section->getSchool()->getId(),
            'section_id' => (int) $section->getId(),
            'subject_id' => (int) $load->getSubject()->getId(),
        ]);
    }
}
