<?php

declare(strict_types=1);

namespace App\Attendance\Application\UseCase\Handler;

use App\Attendance\Application\Service\AttendancePolicy;
use App\Attendance\Application\Service\AttendanceRecorder;
use App\Attendance\Application\Service\AttendanceScope;
use App\Attendance\Application\UseCase\Command\SubmitSf2Command;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<SubmitSf2Command> */
#[AsUseCaseHandler(SubmitSf2Command::class)]
final class SubmitSf2Handler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly AttendanceScope $scope,
        private readonly AttendancePolicy $policy,
        private readonly AttendanceRecorder $recorder,
    ) {
    }

    /** @param SubmitSf2Command $command */
    public function __invoke(UseCase $command): Result
    {
        $this->locks->acquire(AttendanceRecorder::LOCK . $command->sectionId, AttendanceRecorder::BUSY);
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->ownSection($actor, $command->sectionId);
        $this->policy->deny($actor, AttendancePolicy::TAKE, $section);
        $this->recorder->submit($section, $command->month, $actor, $command->confirmMissing);

        return Result::success(null);
    }
}
