<?php

declare(strict_types=1);

namespace App\Attendance\Application\Query\Handler;

use App\Attendance\Application\Query\Command\GetRollCallPageCommand;
use App\Attendance\Application\Service\AttendancePolicy;
use App\Attendance\Application\Service\AttendanceRules;
use App\Attendance\Application\Service\AttendanceScope;
use App\Attendance\Domain\Model\LearnerMark;
use App\Attendance\Domain\Model\Repository\RollCallRepositoryInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Api\RosterEntry;
use App\Learners\Application\Api\SectionRosterInterface;

/** @implements QueryHandlerInterface<GetRollCallPageCommand> */
#[AsQueryHandler(GetRollCallPageCommand::class)]
final class GetRollCallPageHandler implements QueryHandlerInterface
{
    public const CLOSED = 'You can no longer take attendance for this section.';

    public function __construct(
        private readonly AttendanceScope $scope,
        private readonly AttendancePolicy $policy,
        private readonly AttendanceRules $rules,
        private readonly SectionRosterInterface $roster,
        private readonly RollCallRepositoryInterface $rollCalls,
    ) {
    }

    /** @param GetRollCallPageCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->advisory($actor);
        $today = $this->rules->today();
        $day = $command->day ?? $today;

        $refusal = $this->policy->can($actor, AttendancePolicy::TAKE, $section)
            ? $this->rules->refusalToTake($section, $day)
            : self::CLOSED;
        $entries = $this->roster->forMonth($section, $day);
        $counted = array_values(array_filter($entries, static fn (RosterEntry $e) => $e->counts($day)));
        $rollCall = $this->rollCalls->findOne($section, $day);

        $counts = ['present' => \count($counted)]
            + array_fill_keys(array_map(static fn (LearnerMark $m) => $m->value, LearnerMark::cases()), 0);
        foreach ($counted as $entry) {
            if (null !== $mark = $rollCall?->markFor($entry->enrollment)) {
                ++$counts[$mark->value];
                $counts['present'] -= LearnerMark::Absent === $mark ? 1 : 0;
            }
        }
        $next = self::weekday($day, 1);

        return Result::success([
            'section' => $section,
            'day' => $day,
            'today' => $today,
            'refusal' => $refusal,
            'entries' => $entries,
            'counted' => $counted,
            'rollCall' => $rollCall,
            'counts' => $counts,
            'previousDay' => self::weekday($day, -1),
            'nextDay' => $next <= $today ? $next : null,
        ]);
    }

    /** The weekday $step days away (skipping weekends). */
    private static function weekday(\DateTimeImmutable $day, int $step): \DateTimeImmutable
    {
        do {
            $day = $day->modify(($step > 0 ? '+' : '-') . '1 day');
        } while ((int) $day->format('N') > 5);

        return $day;
    }
}
