<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Grading\Application\Query\Command\GetGradeSheetCommand;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradeRules;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Application\Service\GridColumn;
use App\Grading\Application\Service\SectionGradeGrid;
use App\Grading\Domain\Model\ClassGradeStatus;
use App\Grading\Domain\Model\GradeChangeRequest;
use App\Grading\Domain\Model\Repository\GradeChangeRequestRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;

/** @implements QueryHandlerInterface<GetGradeSheetCommand> */
#[AsQueryHandler(GetGradeSheetCommand::class)]
final class GetGradeSheetHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly GradeRules $rules,
        private readonly GradeRepositoryInterface $grades,
        private readonly SectionGradeGrid $grid,
        private readonly GradeChangeRequestRepositoryInterface $changeRequests,
    ) {
    }

    /** @param GetGradeSheetCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        [$load, $period] = $this->scope->loadAndPeriod($command->loadId, $command->periodId);
        $this->policy->deny($actor, GradePolicy::VIEW, $load);

        $section = $load->getSection();
        $subject = $load->getSubject();
        $learners = $this->rules->sheet($section);
        // by enrollment: a learner who transferred out has moved section but keeps the grades given here
        $saved = $this->grades->findForEnrollments(GradeRules::enrollmentIds($learners), $subject, $period);
        $status = $this->rules->isGradable($load) ? $this->rules->status($section, $subject, $period) : null;
        $refusal = null === $status ? "This class isn't graded here." : $this->rules->refusalToEdit($status, $period);

        // for comparison only: this subject's previous period, and the other subjects' grades this period
        $previousPeriod = $section->getSchoolYear()->getGradingPeriod($period->getNumber() - 1);
        $previous = null === $previousPeriod ? [] : $this->grades->findForEnrollments(
            GradeRules::enrollmentIds($learners),
            $subject,
            $previousPeriod,
        );
        $grid = $this->grid->build($section, $period);
        $others = array_values(array_filter(
            $grid['columns'],
            static fn (GridColumn $c): bool => 's' . $subject->getId() !== $c->key,
        ));
        $otherCells = [];
        foreach ($grid['rows'] as $row) {
            $otherCells[(int) $row['learner']->enrollment->getId()] = $row['cells'];
        }

        $changeRequests = $this->changeRequests->findForClass($section, $subject, $period);
        $waiting = array_filter($changeRequests, static fn (GradeChangeRequest $r): bool => $r->isPending());
        $canEdit = $this->policy->can($actor, GradePolicy::EDIT, $load);

        return Result::success([
            'load' => $load,
            'period' => $period,
            'periods' => $section->getSchoolYear()->getGradingPeriods(),
            'learners' => $learners,
            'saved' => $saved,
            'previous_period' => $previousPeriod,
            'previous' => $previous,
            'other_columns' => $others,
            'other_cells' => $otherCells,
            'status' => $status,
            'refusal' => $refusal,
            'change_requests' => $changeRequests,
            // the requests this account sent, which it may withdraw while they wait
            'withdrawable' => array_values(array_map(
                static fn (GradeChangeRequest $r): int => (int) $r->getId(),
                array_filter(
                    $changeRequests,
                    fn (GradeChangeRequest $r): bool => $this->policy->can($actor, GradePolicy::CHANGE_WITHDRAW, $r),
                ),
            )),
            'pending' => [] !== $waiting,
            // "your" request only for the teacher who sent it, not the adviser or a teacher who took over the class
            'own_pending' => [] !== array_filter(
                $waiting,
                static fn (GradeChangeRequest $r): bool => $r->getRequestedBy()->getId() === $actor->getId(),
            ),
            'can_edit' => $canEdit,
            'can_request' => $canEdit && ClassGradeStatus::Locked === $status && [] === $waiting,
            'school_id' => (int) $section->getSchool()->getId(),
            'section_id' => (int) $section->getId(),
            'subject_id' => (int) $subject->getId(),
        ]);
    }
}
