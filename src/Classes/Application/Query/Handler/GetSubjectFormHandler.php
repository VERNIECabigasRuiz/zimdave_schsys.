<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Handler;

use App\Classes\Application\Query\Command\GetSubjectFormCommand;
use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Service\Subject\SubjectScope;
use App\Classes\Application\Service\Subject\SubjectScopeResolver;
use App\Classes\Application\Transformer\SubjectTransformer;
use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use App\Classes\Domain\Model\Subject;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Domain\Model\GradeLevel;

/** @implements QueryHandlerInterface<GetSubjectFormCommand> */
#[AsQueryHandler(GetSubjectFormCommand::class)]
final class GetSubjectFormHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly ClassesScope $classes,
        private readonly SubjectScopeResolver $resolver,
        private readonly SubjectRepositoryInterface $subjects,
    ) {
    }

    /** @param GetSubjectFormCommand $command */
    public function __invoke(Query $command): Result
    {
        $scope = $this->resolver->resolve($command->scope);
        $subject = null !== $command->subjectId ? $this->resolver->subject($scope, $command->subjectId) : null;
        $parents = array_values(array_filter(
            $this->subjects->findForSchool($scope->school, $scope->schoolYear),
            static fn (Subject $s) => !$s->isComponent() && $s !== $subject && $scope->covers($s->getGradeLevel()),
        ));

        return Result::success([
            'school' => ['id' => (int) $scope->school->getId(), 'name' => $scope->school->getName()],
            'schoolYear' => ['name' => $scope->schoolYear->getName()],
            'subject' => null !== $subject ? SubjectTransformer::toArray($subject) : null,
            'defaults' => null === $subject ? $this->defaults($scope, $command->grade) : null,
            'parents' => array_map(static fn (Subject $parent) => [
                'id' => (int) $parent->getId(),
                'label' => $parent->getLabel(),
                'gradeLevel' => $parent->getGradeLevel(),
                'semester' => $parent->getSemester(),
                'track' => $parent->getTrack(),
                'strand' => $parent->getStrand(),
            ], $parents),
            'grades' => $scope->grades,
            'refusal' => $this->classes->blockedReason($scope->school),
        ]);
    }

    /**
     * A new subject starts in the page's only grade, or the grade asked for, after that grade's
     * subjects; a Senior High one in the page's track and strand.
     *
     * @return array{gradeLevel: ?GradeLevel, position: int, track: ?\App\Classes\Domain\Model\ShsTrack,
     *     strand: ?string}
     */
    private function defaults(SubjectScope $scope, ?int $grade): array
    {
        $gradeLevel = 1 === \count($scope->grades) ? $scope->grades[0] : GradeLevel::tryFrom($grade ?? -1);
        if (null === $gradeLevel || !$scope->covers($gradeLevel)) {
            return ['gradeLevel' => null, 'position' => 1, 'track' => null, 'strand' => null];
        }
        $seniorHigh = $gradeLevel->isSeniorHigh();

        return [
            'gradeLevel' => $gradeLevel,
            'position' => $this->subjects->nextPosition($scope->school, $scope->schoolYear, $gradeLevel),
            'track' => $seniorHigh ? $scope->defaultTrack : null,
            'strand' => $seniorHigh ? $scope->defaultStrand : null,
        ];
    }
}
