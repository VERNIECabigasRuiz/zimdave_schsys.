<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Learners\Application\Service\LearnerChanges;
use App\Learners\Application\Service\LearnerEnrollment;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\UseCase\Command\EnrollLearnerCommand;
use App\Learners\Domain\Exception\LearnerNotFoundException;
use App\Learners\Domain\Model\Repository\LearnerRepositoryInterface;

/** @implements UseCaseHandlerInterface<EnrollLearnerCommand> */
#[AsUseCaseHandler(EnrollLearnerCommand::class)]
final class EnrollLearnerHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly LearnerChanges $changes,
        private readonly LearnersScope $scope,
        private readonly LearnerPolicy $policy,
        private readonly LearnerEnrollment $enrollment,
        private readonly LearnerRepositoryInterface $learners,
    ) {
    }

    /** @param EnrollLearnerCommand $command */
    public function __invoke(UseCase $command): Result
    {
        if (null !== $command->learnerId) {
            $this->changes->lock($command->learnerId);
        }
        $this->changes->lockSchool($command->schoolId);
        $actor = $this->scope->account($command->actorId);
        $page = $this->scope->page($command->schoolId, $command->fixedSectionId);
        $section = $page->fixedSection ?? $this->scope->section($page, (int) $command->sectionId);
        $this->policy->deny($actor, LearnerPolicy::ENROLL, $section);

        if (null === $command->learnerId) {
            \assert(null !== $command->details);
            $learner = $this->enrollment->newLearner($command->lrn);
            $command->details->applyTo($learner);
            $password = $this->enrollment->enrollNew($learner, $section, $actor);
        } else {
            $learner = $this->learners->findById($command->learnerId) ?? throw new LearnerNotFoundException();
            $this->enrollment->enrollExisting($learner, $section, $actor);
            $password = null;
        }

        return Result::success([
            'fullName' => $learner->getUser()->getFullName(),
            'username' => $learner->getUser()->getUsername(),
            'password' => $password,
            'sectionId' => (int) $section->getId(),
            'sectionLabel' => $section->getLabel(),
        ]);
    }
}
