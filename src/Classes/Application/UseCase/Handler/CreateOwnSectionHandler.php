<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Handler;

use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Service\OwnSectionGate;
use App\Classes\Application\Service\SectionManager;
use App\Classes\Application\UseCase\Command\CreateOwnSectionCommand;
use App\Classes\Domain\Model\Section;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Domain\Exception\SchoolNotFoundException;

/** @implements UseCaseHandlerInterface<CreateOwnSectionCommand> */
#[AsUseCaseHandler(CreateOwnSectionCommand::class)]
final class CreateOwnSectionHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly ClassesScope $scope,
        private readonly OwnSectionGate $gate,
        private readonly SectionManager $manager,
    ) {
    }

    /** @param CreateOwnSectionCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $teacher = $this->scope->account($command->teacherId);
        [$school, $schoolYear] = $this->gate->check($teacher) ?? throw new SchoolNotFoundException();
        $section = (new Section($school, $schoolYear))
            ->setGradeLevel($command->gradeLevel)
            ->setName($command->name)
            ->setTrack($command->track)
            ->setStrand($command->strand)
            ->setAdviser($teacher);
        $this->manager->create($section);

        return Result::success(['label' => $section->getLabel()]);
    }
}
