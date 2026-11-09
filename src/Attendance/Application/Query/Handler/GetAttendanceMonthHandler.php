<?php

declare(strict_types=1);

namespace App\Attendance\Application\Query\Handler;

use App\Attendance\Application\Query\Command\GetAttendanceMonthCommand;
use App\Attendance\Application\Service\AttendancePolicy;
use App\Attendance\Application\Service\AttendanceRules;
use App\Attendance\Application\Service\AttendanceScope;
use App\Attendance\Application\Service\Sf2ReportBuilder;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<GetAttendanceMonthCommand> */
#[AsQueryHandler(GetAttendanceMonthCommand::class)]
final class GetAttendanceMonthHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly AttendanceScope $scope,
        private readonly AttendancePolicy $policy,
        private readonly AttendanceRules $rules,
        private readonly Sf2ReportBuilder $reports,
    ) {
    }

    /** @param GetAttendanceMonthCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->pageSection($actor, $command->schoolId, $command->sectionId);
        $month = $command->month ?? $this->rules->today()->modify('first day of this month');
        $schoolYear = $section->getSchoolYear();
        $first = $schoolYear->getStartDate()?->modify('first day of this month');
        $last = $schoolYear->getEndDate()?->modify('first day of this month');
        $previous = $month->modify('-1 month');
        $next = $month->modify('+1 month');
        $canTake = null === $command->schoolId && $this->policy->can($actor, AttendancePolicy::TAKE, $section);
        // the adviser's page offers the submit form once the month is over and still open
        $canSubmit = $canTake
            && !$this->rules->isLocked($section, $month)
            && $this->rules->monthHasEnded($section, $month);

        return Result::success([
            'section' => $section,
            'month' => $month,
            'report' => $this->reports->build($section, $month),
            'today' => $this->rules->today(),
            'previous' => null === $first || $previous >= $first ? $previous : null,
            'next' => null === $last || $next <= $last ? $next : null,
            'canTake' => $canTake,
            'canSubmit' => $canSubmit,
            'missingDays' => null === $command->schoolId ? \count($this->rules->missingDays($section, $month)) : 0,
            'canReopen' => null !== $command->schoolId
                && $this->policy->can($actor, AttendancePolicy::REOPEN, $section),
        ]);
    }
}
