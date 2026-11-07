<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Handler;

use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Service\SectionManager;
use App\Classes\Application\UseCase\Command\CreateSectionCommand;
use App\Classes\Domain\Model\Section;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<CreateSectionCommand> */
#[AsUseCaseHandler(CreateSectionCommand::class)]
final class CreateSectionHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly ClassesScope $scope,
        private readonly SectionManager $manager,
    ) {
    }

    /** @param CreateSectionCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $section = (new Section($school, $this->scope->currentYear()))
            ->setGradeLevel($command->gradeLevel)
            ->setName($command->name)
            ->setTrack($command->track)
            ->setStrand($command->strand)
            ->setAdviser(null !== $command->adviserId ? $this->scope->account($command->adviserId) : null);
        $this->manager->create($section);

        return Result::success(['label' => $section->getLabel()]);
    }
}
