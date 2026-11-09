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
use App\Grading\Application\UseCase\Command\ApproveGradeChangeCommand;
use App\Grading\Domain\Exception\GradingNotFoundException;

/** @implements UseCaseHandlerInterface<ApproveGradeChangeCommand> */
#[AsUseCaseHandler(ApproveGradeChangeCommand::class)]
final class ApproveGradeChangeHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly GradeChangeManager $manager,
    ) {
    }

    /** @param ApproveGradeChangeCommand $command */
    public function __invoke(UseCase $command): Result
    {
        // the class lock keeps sheet saves out; the school lock keeps out a withdraw or decline of this request,
        // and the principal's lock changes, until the grades are in
        $this->locks->acquire(GradeRecorder::classLock($command->sectionId, $command->subjectId), GradeRecorder::BUSY);
        $this->locks->acquire(GradeRecorder::schoolLock($command->schoolId), GradeRecorder::BUSY);

        $actor = $this->scope->account($command->actorId);
        $request = $this->scope->changeRequest($command->requestId, $this->scope->school($command->schoolId));
        if (
            $request->getSection()->getId() !== $command->sectionId
            || $request->getSubject()->getId() !== $command->subjectId
        ) {
            throw new GradingNotFoundException();
        }
        $this->policy->deny($actor, GradePolicy::CHANGE_DECIDE, $request);
        $this->manager->approve($request, $actor);

        return Result::success(null);
    }
}
