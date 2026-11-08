<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradeRecorder;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Application\UseCase\Command\SaveGradeSheetCommand;
use App\Grading\Domain\Exception\GradingNotFoundException;

/** @implements UseCaseHandlerInterface<SaveGradeSheetCommand> */
#[AsUseCaseHandler(SaveGradeSheetCommand::class)]
final class SaveGradeSheetHandler implements UseCaseHandlerInterface
{
    public const CANNOT_EDIT = 'You cannot enter grades for this class.';

    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly GradeRecorder $recorder,
    ) {
    }

    /** @param SaveGradeSheetCommand $command */
    public function __invoke(UseCase $command): Result
    {
        // the class's own lock keeps two saves of it apart; a shared hold on the school's grade lock keeps
        // the principal from locking the period, or relocking the class, until this save is in
        $this->locks->acquire(GradeRecorder::classLock($command->sectionId, $command->subjectId), GradeRecorder::BUSY);
        $this->locks->acquireShared(GradeRecorder::schoolLock($command->schoolId), GradeRecorder::BUSY);

        $actor = $this->scope->account($command->actorId);
        [$load, $period] = $this->scope->loadAndPeriod($command->loadId, $command->periodId);
        $section = $load->getSection();
        if (
            $section->getId() !== $command->sectionId
            || $load->getSubject()->getId() !== $command->subjectId
            || $section->getSchool()->getId() !== $command->schoolId
        ) {
            throw new GradingNotFoundException(GradingScope::NO_CLASS);
        }
        $this->policy->deny($actor, GradePolicy::EDIT, $load, self::CANNOT_EDIT);

        return Result::success($this->recorder->saveSheet($load, $period, $command->values, $actor));
    }
}
