<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Handler;

use App\Classes\Application\Service\Subject\SubjectScopeResolver;
use App\Classes\Application\Service\SubjectManager;
use App\Classes\Application\UseCase\Command\UpdateSubjectCommand;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<UpdateSubjectCommand> */
#[AsUseCaseHandler(UpdateSubjectCommand::class)]
final class UpdateSubjectHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SubjectScopeResolver $resolver,
        private readonly SubjectManager $manager,
    ) {
    }

    /** @param UpdateSubjectCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $scope = $this->resolver->resolve($command->scope);
        $subject = $this->resolver->subject($scope, $command->id);
        $parent = null !== $command->parentId ? $this->resolver->subject($scope, $command->parentId) : null;
        $this->manager->update($subject, $command, $parent);

        return Result::success(['label' => $subject->getLabel()]);
    }
}
