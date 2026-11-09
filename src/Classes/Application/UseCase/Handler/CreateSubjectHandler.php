<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Handler;

use App\Classes\Application\Service\Subject\SubjectScopeResolver;
use App\Classes\Application\Service\SubjectManager;
use App\Classes\Application\UseCase\Command\CreateSubjectCommand;
use App\Classes\Domain\Model\Subject;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<CreateSubjectCommand> */
#[AsUseCaseHandler(CreateSubjectCommand::class)]
final class CreateSubjectHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SubjectScopeResolver $resolver,
        private readonly SubjectManager $manager,
    ) {
    }

    /** @param CreateSubjectCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $scope = $this->resolver->resolve($command->scope);
        $parent = null !== $command->parentId ? $this->resolver->subject($scope, $command->parentId) : null;
        $subject = (new Subject($scope->school, $scope->schoolYear))
            ->setGradeLevel($command->gradeLevel)
            ->setCode($command->code)
            ->setName($command->name)
            ->setPosition($command->position)
            ->setMeetingsPerWeek($command->meetingsPerWeek)
            ->setSemester($command->semester)
            ->setTrack($command->track)
            ->setStrand($command->strand)
            ->setParent($parent);
        $this->manager->create($subject);

        return Result::success(['label' => $subject->getLabel()]);
    }
}
