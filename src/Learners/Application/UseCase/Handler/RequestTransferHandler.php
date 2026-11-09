<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Learners\Application\Service\LearnerChanges;
use App\Learners\Application\Service\LearnerLookup;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\Service\LearnerScope;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\Service\LookupKind;
use App\Learners\Application\Service\TransferRequests;
use App\Learners\Application\UseCase\Command\RequestTransferCommand;
use App\Learners\Domain\Exception\TransferLookupChangedException;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;

/** @implements UseCaseHandlerInterface<RequestTransferCommand> */
#[AsUseCaseHandler(RequestTransferCommand::class)]
final class RequestTransferHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly LearnerChanges $changes,
        private readonly LearnersScope $scope,
        private readonly LearnerPolicy $policy,
        private readonly LearnerLookup $lookup,
        private readonly EnrollmentRepositoryInterface $enrollments,
        private readonly TransferRequests $transfers,
    ) {
    }

    /** @param RequestTransferCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->changes->lock($command->learnerId);
        $actor = $this->scope->account($command->actorId);
        $school = $this->scope->school($command->schoolId);
        $this->policy->deny($actor, LearnerPolicy::REQUEST_TRANSFER, $school);
        $page = new LearnerScope($school, $this->scope->currentYear());

        $found = $this->lookup->find($command->lrn, $school, $page->schoolYear);
        if (null !== $found->enrollment) {
            // read it again: the form query may have loaded an older copy before the lock was taken
            $this->enrollments->findFresh((int) $found->enrollment->getId());
            $found = $this->lookup->find($command->lrn, $school, $page->schoolYear);
        }
        if (
            LookupKind::Elsewhere !== $found->kind
            || null === $found->enrollment
            || $found->enrollment->getLearner()->getId() !== $command->learnerId
        ) {
            throw new TransferLookupChangedException();
        }
        $to = $this->scope->section($page, (int) $command->sectionId);
        $this->transfers->request($found->enrollment, $to, $actor);

        return Result::success(['schoolName' => $found->enrollment->getSchool()->getName()]);
    }
}
