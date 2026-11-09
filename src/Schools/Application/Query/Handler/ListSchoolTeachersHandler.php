<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Query\Command\ListSchoolTeachersCommand;
use App\Schools\Application\Service\SchoolTeacherScope;
use App\Schools\Application\Transformer\SchoolTransformer;
use App\Schools\Application\Transformer\SchoolYearTransformer;
use App\Schools\Application\Transformer\TeacherTransformer;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Schools\Domain\Service\Plan\PlanLimits;

/** @implements QueryHandlerInterface<ListSchoolTeachersCommand> */
#[AsQueryHandler(ListSchoolTeachersCommand::class)]
final class ListSchoolTeachersHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly SchoolTeacherScope $scope,
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly PlanLimits $planLimits,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param ListSchoolTeachersCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->schoolYears->findCurrent();
        $previous = null !== $schoolYear ? $this->schoolYears->findPrevious($schoolYear) : null;
        $canBringBack = null !== $schoolYear && null !== $previous
            && [] !== $this->assignments->findTeachersToBringBack($school, $previous, $schoolYear);

        return Result::success([
            'school' => SchoolTransformer::toArray($school, $this->clock->now()),
            'schoolYear' => null !== $schoolYear ? SchoolYearTransformer::toArray($schoolYear) : null,
            'assignments' => null === $schoolYear ? [] : array_map(
                static fn (SchoolAssignment $assignment) => [
                    'user' => TeacherTransformer::toArray($assignment->getUser()),
                ],
                $this->assignments->findTeacherAssignments($school, $schoolYear),
            ),
            'used' => null !== $schoolYear ? $this->planLimits->countTeachers($school, $schoolYear) : 0,
            'addRefusal' => null !== $schoolYear ? $this->planLimits->canAddTeacher($school, $schoolYear) : null,
            'bringBackFrom' => $canBringBack ? SchoolYearTransformer::toArray($previous) : null,
        ]);
    }
}
