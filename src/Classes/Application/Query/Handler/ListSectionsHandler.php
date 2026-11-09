<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Handler;

use App\Classes\Application\Query\Command\ListSectionsCommand;
use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Transformer\SectionTransformer;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Classes\Domain\Service\Usage\SectionLearnersInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** @implements QueryHandlerInterface<ListSectionsCommand> */
#[AsQueryHandler(ListSectionsCommand::class)]
final class ListSectionsHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly ClassesScope $scope,
        private readonly SchoolDirectoryInterface $schools,
        private readonly SectionRepositoryInterface $sections,
        private readonly SectionLearnersInterface $learners,
    ) {
    }

    /** @param ListSectionsCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->schools->currentYear();
        if (null === $schoolYear) {
            return Result::success([
                'schoolYear' => null,
                'groups' => [],
                'enrolled' => [],
                'refusal' => $this->scope->blockedReason($school),
                'bringBackFrom' => null,
            ]);
        }

        $groups = [];
        foreach ($this->sections->findForSchool($school, $schoolYear) as $section) {
            $groups[$section->getGradeLevel()?->label() ?? ''][] = SectionTransformer::toArray($section);
        }
        $previous = $this->schools->previousYear($schoolYear);
        $bringBackFrom = null;
        if (null !== $previous && [] !== $this->sections->findBringBackCandidates($school, $previous, $schoolYear)) {
            $bringBackFrom = ['name' => $previous->getName()];
        }

        return Result::success([
            'schoolYear' => ['name' => $schoolYear->getName()],
            'groups' => $groups,
            'enrolled' => $this->learners->countEnrolledBySection($school, $schoolYear),
            'refusal' => $this->scope->blockedReason($school),
            'bringBackFrom' => $bringBackFrom,
        ]);
    }
}
