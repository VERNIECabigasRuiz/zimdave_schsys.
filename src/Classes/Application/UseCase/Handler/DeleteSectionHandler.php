<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Handler;

use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Service\SectionManager;
use App\Classes\Application\UseCase\Command\DeleteSectionCommand;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<DeleteSectionCommand> */
#[AsUseCaseHandler(DeleteSectionCommand::class)]
final class DeleteSectionHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly ClassesScope $scope,
        private readonly SectionManager $manager,
    ) {
    }

    /** @param DeleteSectionCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $section = $this->scope->section($school, $this->scope->currentYear(), $command->sectionId);
        $label = $section->getLabel();
        $this->manager->delete($section);

        return Result::success(['label' => $label]);
    }
}
