<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Query\Command\ListSchoolsCommand;
use App\Schools\Application\Transformer\SchoolTransformer;
use App\Schools\Application\Transformer\SchoolYearTransformer;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/** @implements QueryHandlerInterface<ListSchoolsCommand> */
#[AsQueryHandler(ListSchoolsCommand::class)]
final class ListSchoolsHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param ListSchoolsCommand $command */
    public function __invoke(Query $command): Result
    {
        $schoolYear = null === $command->schoolYearId
            ? $this->schoolYears->findCurrent()
            : $this->schoolYears->findById($command->schoolYearId) ?? throw new SchoolYearNotFoundException();
        $principals = [];
        foreach (null !== $schoolYear ? $this->assignments->findPrincipalsBySchool($schoolYear) : [] as $id => $a) {
            $user = $a->getUser();
            $principals[$id] = ['user' => [
                'id' => (int) $user->getId(),
                'fullName' => $user->getFullName(),
                'active' => $user->isActive(),
            ]];
        }
        // Offered only while the selected year has no principals yet
        $previous = null !== $schoolYear && [] === $principals ? $this->schoolYears->findPrevious($schoolYear) : null;
        $today = $this->clock->now();

        return Result::success([
            'schools' => array_map(
                static fn (School $school) => SchoolTransformer::toArray($school, $today),
                $this->schools->findAllOrdered(),
            ),
            'schoolYears' => array_map(
                static fn (SchoolYear $sy) => SchoolYearTransformer::toArray($sy),
                $this->schoolYears->findAllNewestFirst(),
            ),
            'schoolYear' => null !== $schoolYear ? SchoolYearTransformer::toArray($schoolYear) : null,
            'principals' => $principals,
            'teacherCounts' => null !== $schoolYear ? $this->assignments->countTeachersBySchool($schoolYear) : [],
            'previousYear' => null !== $previous ? SchoolYearTransformer::toArray($previous) : null,
            'today' => $today,
        ]);
    }
}
