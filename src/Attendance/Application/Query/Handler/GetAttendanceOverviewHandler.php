<?php

declare(strict_types=1);

namespace App\Attendance\Application\Query\Handler;

use App\Attendance\Application\Query\Command\GetAttendanceOverviewCommand;
use App\Attendance\Application\Service\AttendanceRules;
use App\Attendance\Application\Service\AttendanceScope;
use App\Attendance\Application\Service\AttendanceStatistics;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<GetAttendanceOverviewCommand> */
#[AsQueryHandler(GetAttendanceOverviewCommand::class)]
final class GetAttendanceOverviewHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly AttendanceScope $scope,
        private readonly AttendanceRules $rules,
        private readonly AttendanceStatistics $statistics,
    ) {
    }

    /** @param GetAttendanceOverviewCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->scope->currentYear();
        $month = $command->month ?? $this->rules->today()->modify('first day of this month');
        $months = $this->statistics->month($school, $schoolYear, $month);

        $byGrade = [];
        foreach ($months as $sectionMonth) {
            $byGrade[$sectionMonth->section->getGradeLevel()?->label() ?? 'No grade level'][] = $sectionMonth;
        }

        $first = $schoolYear->getStartDate()?->modify('first day of this month');
        $last = $schoolYear->getEndDate()?->modify('first day of this month');
        $monthChoices = [];
        for ($m = $first ?? $month; $m <= ($last ?? $month); $m = $m->modify('+1 month')) {
            $monthChoices[] = $m;
        }

        return Result::success([
            'school' => $school,
            'schoolYear' => $schoolYear,
            'month' => $month,
            'months' => $monthChoices,
            'today' => $this->statistics->today($school, $schoolYear),
            'byGrade' => $byGrade,
            'followUps' => $this->statistics->followUps($months),
        ]);
    }
}
