<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Handler;

use App\Classes\Application\Api\SectionDirectoryInterface;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Learners\Application\Service\LearnerChanges;
use App\Learners\Application\Service\LearnerEnrollment;
use App\Learners\Application\UseCase\Command\TransferLearnerCommand;

/** @implements UseCaseHandlerInterface<TransferLearnerCommand> */
#[AsUseCaseHandler(TransferLearnerCommand::class)]
final class TransferLearnerHandler implements UseCaseHandlerInterface
{
    public const NOT_ELSEWHERE = 'This section is not at another school this school year.';

    public function __construct(
        private readonly LearnerChanges $changes,
        private readonly SectionDirectoryInterface $sections,
        private readonly LearnerEnrollment $enrollment,
    ) {
    }

    /** @param TransferLearnerCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->changes->lock($command->learnerId, $command->toSchoolId);
        $target = $this->changes->target($command, null, false);
        $to = $this->sections->findSection($command->sectionId);
        if (
            null === $to
            || $to->getSchool()->getId() !== $command->toSchoolId
            || $to->getSchool()->getId() === $target->page->school->getId()
            || $to->getSchoolYear()->getId() !== $target->page->schoolYear->getId()
        ) {
            throw new SectionNotFoundException(self::NOT_ELSEWHERE);
        }
        $this->enrollment->transferDirectly($target->enrollment(), $to);

        return Result::success([
            'fullName' => $target->learner->getUser()->getFullName(),
            'sectionLabel' => $to->getLabel(),
            'schoolName' => $to->getSchool()->getName(),
        ]);
    }
}
