<?php

declare(strict_types=1);

namespace App\Dashboards\Application\Query\Handler;

use App\Classes\Application\Api\TimetableDirectoryInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Dashboards\Application\Query\Command\GetStudentDashboardCommand;
use App\Dashboards\Application\Service\DashboardScope;
use App\Learners\Application\Api\LearnerDirectoryInterface;

/** @implements QueryHandlerInterface<GetStudentDashboardCommand> */
#[AsQueryHandler(GetStudentDashboardCommand::class)]
final class GetStudentDashboardHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly DashboardScope $scope,
        private readonly LearnerDirectoryInterface $learners,
        private readonly TimetableDirectoryInterface $timetable,
    ) {
    }

    /** @param GetStudentDashboardCommand $command */
    public function __invoke(Query $command): Result
    {
        $schoolYear = $this->scope->currentYear();
        $learner = $this->learners->learnerOf($this->scope->account($command->actorId));
        $enrollment = null !== $schoolYear && null !== $learner
            ? $this->learners->currentEnrollment($learner, $schoolYear)
            : null;

        $section = $enrollment?->getSection();
        $state = null;
        if (null !== $section) {
            $terms = $this->timetable->termsFor($section);
            $term = $command->term ?? $terms[0];
            $state = $this->timetable->sectionState($section, \in_array($term, $terms, true) ? $term : $terms[0]);
        }

        return Result::success([
            'school_year' => $schoolYear,
            'learner' => $learner,
            'enrollment' => $enrollment,
            'remarks_text' => null !== $enrollment ? $this->learners->remarksText($enrollment) : null,
            'section' => $section,
            'state' => $state,
        ]);
    }
}
