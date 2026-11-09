<?php

declare(strict_types=1);

namespace App\Attendance\Application\UseCase\Handler;

use App\Attendance\Application\Service\AttendancePolicy;
use App\Attendance\Application\Service\AttendanceRecorder;
use App\Attendance\Application\Service\AttendanceScope;
use App\Attendance\Application\UseCase\Command\ReopenSf2Command;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<ReopenSf2Command> */
#[AsUseCaseHandler(ReopenSf2Command::class)]
final class ReopenSf2Handler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly AttendanceScope $scope,
        private readonly AttendancePolicy $policy,
        private readonly AttendanceRecorder $recorder,
    ) {
    }

    /** @param ReopenSf2Command $command */
    public function __invoke(UseCase $command): Result
    {
        $this->locks->acquire(AttendanceRecorder::LOCK . $command->sectionId, AttendanceRecorder::BUSY);
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->section($this->scope->school($command->schoolId), $command->sectionId);
        $this->policy->deny($actor, AttendancePolicy::REOPEN, $section);
        $this->recorder->reopen($section, $command->month);

        return Result::success(null);
    }
}
