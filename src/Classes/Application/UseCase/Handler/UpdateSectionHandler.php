<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Handler;

use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Service\SectionManager;
use App\Classes\Application\UseCase\Command\UpdateSectionCommand;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<UpdateSectionCommand> */
#[AsUseCaseHandler(UpdateSectionCommand::class)]
final class UpdateSectionHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly ClassesScope $scope,
        private readonly SectionManager $manager,
    ) {
    }

    /** @param UpdateSectionCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $section = $this->scope->section($school, $this->scope->currentYear(), $command->id);
        \assert(null !== $command->gradeLevel);
        $this->manager->update(
            $section,
            $command->gradeLevel,
            $command->name,
            $command->track,
            $command->strand,
            null !== $command->adviserId ? $this->scope->account($command->adviserId) : null,
        );

        return Result::success(['label' => $section->getLabel()]);
    }
}
