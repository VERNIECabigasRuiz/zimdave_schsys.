<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Handler;

use App\Classes\Application\Service\Subject\SubjectScopeResolver;
use App\Classes\Application\Service\SubjectManager;
use App\Classes\Application\UseCase\Command\DeleteSubjectCommand;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<DeleteSubjectCommand> */
#[AsUseCaseHandler(DeleteSubjectCommand::class)]
final class DeleteSubjectHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SubjectScopeResolver $resolver,
        private readonly SubjectManager $manager,
    ) {
    }

    /** @param DeleteSubjectCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $subject = $this->resolver->subject($this->resolver->resolve($command->scope), $command->subjectId);
        $label = $subject->getLabel();
        $this->manager->delete($subject);

        return Result::success(['label' => $label]);
    }
}
