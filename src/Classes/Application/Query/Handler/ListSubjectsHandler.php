<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Handler;

use App\Classes\Application\Query\Command\ListSubjectsCommand;
use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Service\Subject\SubjectScopeResolver;
use App\Classes\Application\Transformer\SubjectTransformer;
use App\Classes\Application\UseCase\Command\SubjectDetails;
use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Service\Subject\DepEdSubjects;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\GradeLevel;

/** @implements QueryHandlerInterface<ListSubjectsCommand> */
#[AsQueryHandler(ListSubjectsCommand::class)]
final class ListSubjectsHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly ClassesScope $classes,
        private readonly SubjectScopeResolver $resolver,
        private readonly SchoolDirectoryInterface $schools,
        private readonly SubjectRepositoryInterface $subjects,
    ) {
    }

    /** @param ListSubjectsCommand $command */
    public function __invoke(Query $command): Result
    {
        // A school's page still opens without a current school year; it says there is none
        if (null !== $command->scope->schoolId && null === $this->schools->currentYear()) {
            $school = $this->classes->school($command->scope->schoolId);

            return Result::success([
                'school' => ['id' => (int) $school->getId(), 'name' => $school->getName()],
                'schoolYear' => null,
                'groups' => [],
                'refusal' => null,
                'depedGrades' => [],
                'bringBackFrom' => null,
            ]);
        }

        $scope = $this->resolver->resolve($command->scope);
        $groups = [];
        $used = [];
        foreach ($this->subjects->findForSchool($scope->school, $scope->schoolYear) as $subject) {
            $grade = $subject->getGradeLevel();
            if (null === $grade || !$scope->covers($grade)) {
                continue;
            }
            $used[$grade->value] = true;
            if (!$subject->isComponent()) {
                $groups[self::groupLabel($subject, $grade)][] = SubjectTransformer::toArray($subject);
            }
        }

        $previous = $this->schools->previousYear($scope->schoolYear);
        $candidates = null !== $previous ? array_filter(
            $this->subjects->findBringBackCandidates($scope->school, $previous, $scope->schoolYear),
            static fn (Subject $s) => $scope->covers($s->getGradeLevel()),
        ) : [];

        return Result::success([
            'school' => ['id' => (int) $scope->school->getId(), 'name' => $scope->school->getName()],
            'schoolYear' => ['name' => $scope->schoolYear->getName()],
            'groups' => $groups,
            'refusal' => $this->classes->blockedReason($scope->school),
            'depedGrades' => array_values(array_filter(
                $scope->grades,
                static fn (GradeLevel $g) => DepEdSubjects::hasPreset($g) && !isset($used[$g->value]),
            )),
            'bringBackFrom' => null !== $previous && [] !== $candidates ? ['name' => $previous->getName()] : null,
        ]);
    }

    /** "Grade 7"; Senior High: "Grade 11 · 1st semester". */
    private static function groupLabel(Subject $subject, GradeLevel $grade): string
    {
        $semester = array_search($subject->getSemester(), SubjectDetails::SEMESTERS, true);

        return false !== $semester ? $grade->label() . ' · ' . $semester : $grade->label();
    }
}
