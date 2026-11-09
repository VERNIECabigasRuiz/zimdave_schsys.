<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Handler;

use App\Classes\Application\Query\Command\GetSectionFormCommand;
use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Transformer\SectionTransformer;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Domain\Model\GradeLevel;

/** @implements QueryHandlerInterface<GetSectionFormCommand> */
#[AsQueryHandler(GetSectionFormCommand::class)]
final class GetSectionFormHandler implements QueryHandlerInterface
{
    public function __construct(private readonly ClassesScope $scope)
    {
    }

    /** @param GetSectionFormCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->scope->currentYear();
        $section = null !== $command->sectionId
            ? SectionTransformer::toArray($this->scope->section($school, $schoolYear, $command->sectionId))
            : null;

        return Result::success([
            'section' => $section,
            'schoolYear' => ['name' => $schoolYear->getName()],
            'refusal' => $this->scope->blockedReason($school),
            'grades' => $school->getType()?->gradeLevels() ?? GradeLevel::cases(),
            'teachers' => $this->scope->teacherChoices($school, $schoolYear),
        ]);
    }
}
