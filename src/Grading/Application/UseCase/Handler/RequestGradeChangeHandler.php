<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Grading\Application\Service\GradeChangeManager;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradeRecorder;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Application\UseCase\Command\RequestGradeChangeCommand;
use App\Grading\Domain\Exception\GradingNotFoundException;

/** @implements UseCaseHandlerInterface<RequestGradeChangeCommand> */
#[AsUseCaseHandler(RequestGradeChangeCommand::class)]
final class RequestGradeChangeHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly GradeChangeManager $manager,
    ) {
    }

    /** @param RequestGradeChangeCommand $command */
    public function __invoke(UseCase $command): Result
    {
        // the class lock keeps it apart from a sheet save and another request; a shared hold on the school's lock
        // keeps the principal from unlocking the period or the class while this one is past its checks
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
        $this->policy->deny($actor, GradePolicy::EDIT, $load);
        $this->manager->request($load, $period, $command->values, $command->reason, $actor);

        return Result::success(null);
    }
}
