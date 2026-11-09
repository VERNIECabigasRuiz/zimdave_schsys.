<?php

declare(strict_types=1);

namespace App\Dashboards\Application\Query\Handler;

use App\Access\Domain\Model\User;
use App\Attendance\Application\Api\AttendanceDirectoryInterface;
use App\Classes\Application\Api\SectionDirectoryInterface;
use App\Classes\Domain\Model\Section;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Dashboards\Application\Query\Command\GetTeacherDashboardCommand;
use App\Dashboards\Application\Service\DashboardScope;
use App\Learners\Application\Api\LearnerDirectoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;

/** @implements QueryHandlerInterface<GetTeacherDashboardCommand> */
#[AsQueryHandler(GetTeacherDashboardCommand::class)]
final class GetTeacherDashboardHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly DashboardScope $scope,
        private readonly SchoolDirectoryInterface $schools,
        private readonly SectionDirectoryInterface $sections,
        private readonly LearnerDirectoryInterface $learners,
        private readonly AttendanceDirectoryInterface $attendance,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param GetTeacherDashboardCommand $command */
    public function __invoke(Query $command): Result
    {
        $teacher = $this->scope->account($command->actorId);
        $schoolYear = $this->scope->currentYear();
        $school = $this->scope->schoolOf($teacher, $schoolYear, User::ROLE_TEACHER);
        $advisory = null === $schoolYear ? null : $this->sections->advisoryOf($teacher, $schoolYear);

        return Result::success([
            'school_year' => $schoolYear,
            'school' => $school,
            'advisory' => $advisory,
            'can_create_section' => null !== $school && null === $advisory && $this->ownsSections($school)
                && null === $this->schools->blockedReason($school),
            'can_manage_subjects' => null !== $school && $this->ownsSections($school),
            // a school without a principal has no timetables
            'show_now' => null !== $school && !$this->ownsSections($school),
            // on a plan without a principal the adviser decides the requests leaving their section
            'transfers_waiting' => null !== $advisory && $this->ownsSections($advisory->getSchool())
                ? $this->learners->pendingTransfersOut($advisory->getSchool(), $advisory->getSchoolYear(), $advisory)
                : 0,
            'attendance_today' => $this->attendanceToday($advisory),
        ]);
    }

    /** @return array{schoolDay: bool, taken: bool} whether the advisory has classes today, and its roll call is in */
    private function attendanceToday(?Section $advisory): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        if (
            null === $advisory
            || !$this->schools->isSchoolDay($advisory->getSchool(), $advisory->getSchoolYear(), $today)
        ) {
            return ['schoolDay' => false, 'taken' => false];
        }

        return ['schoolDay' => true, 'taken' => $this->attendance->rollCallTaken($advisory, $today)];
    }

    /** Teachers manage their own section only when the plan has no principal. */
    private function ownsSections(School $school): bool
    {
        return null !== $school->getPlan() && !$school->getPlan()->includesPrincipal();
    }
}
