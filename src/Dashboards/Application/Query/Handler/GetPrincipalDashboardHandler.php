<?php

declare(strict_types=1);

namespace App\Dashboards\Application\Query\Handler;

use App\Access\Domain\Model\User;
use App\Attendance\Application\Api\AttendanceDirectoryInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Dashboards\Application\Query\Command\GetPrincipalDashboardCommand;
use App\Dashboards\Application\Service\DashboardScope;
use App\Grading\Application\Api\GradingDirectoryInterface;
use App\Learners\Application\Api\LearnerDirectoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** @implements QueryHandlerInterface<GetPrincipalDashboardCommand> */
#[AsQueryHandler(GetPrincipalDashboardCommand::class)]
final class GetPrincipalDashboardHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly DashboardScope $scope,
        private readonly SchoolDirectoryInterface $schools,
        private readonly LearnerDirectoryInterface $learners,
        private readonly AttendanceDirectoryInterface $attendance,
        private readonly GradingDirectoryInterface $grading,
    ) {
    }

    /** @param GetPrincipalDashboardCommand $command */
    public function __invoke(Query $command): Result
    {
        $schoolYear = $this->scope->currentYear();
        $school = $this->scope->schoolOf($this->scope->account($command->actorId), $schoolYear, User::ROLE_PRINCIPAL);
        if (null === $school || null === $schoolYear) {
            return Result::success(['school' => null, 'school_year' => $schoolYear]);
        }
        $gradingPeriod = $this->grading->defaultPeriod($school, $schoolYear);

        return Result::success([
            'school' => $school,
            'school_year' => $schoolYear,
            'teachers' => $this->schools->teacherCount($school, $schoolYear),
            'students' => $this->schools->studentCount($school, $schoolYear),
            'blocked_reason' => $this->schools->blockedReason($school),
            'transfers_waiting' => $this->learners->pendingTransfersOut($school, $schoolYear),
            'attendance_today' => $this->attendance->schoolToday($school, $schoolYear),
            'grade_changes_waiting' => $this->grading->pendingChangeRequests($school, $schoolYear),
            'grading_period' => $gradingPeriod,
            'failing' => null === $gradingPeriod ? 0 : $this->grading->failingCount(
                $school,
                $schoolYear,
                $gradingPeriod,
            ),
        ]);
    }
}
