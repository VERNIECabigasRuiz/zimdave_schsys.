<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Validation;

use App\Classes\Application\Service\Subject\SubjectScopeResolver;
use App\Classes\Application\UseCase\Command\SubjectDetails;
use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use App\Classes\Domain\Model\Section;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidSubjectValidator extends ConstraintValidator
{
    public function __construct(
        private readonly SubjectRepositoryInterface $subjects,
        private readonly SubjectScopeResolver $scopes,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidSubject) {
            throw new UnexpectedTypeException($constraint, ValidSubject::class);
        }
        if (!$value instanceof SubjectDetails) {
            return;
        }
        $scope = $this->scopes->resolve($value->scope);

        // Only timetables need it, and a school without a principal (Individual plan) has none
        $includesPrincipal = $scope->school->getPlan()?->includesPrincipal();
        if (null === $value->meetingsPerWeek && !$value->hasComponents && false !== $includesPrincipal) {
            $this->violation('Set how many times a week this subject meets.', 'meetingsPerWeek');
        }
        $grade = $value->gradeLevel;
        if (null === $grade) {
            return;
        }
        $type = $scope->school->getType();
        if (null !== $type && !\in_array($grade, $type->gradeLevels(), true)) {
            $this->violation(\sprintf('%s is not offered by %s.', $grade->label(), $type->withArticle()), 'gradeLevel');
        }
        $strand = Section::normalizeStrand($value->strand);
        if ($grade->isSeniorHigh() && null === $value->semester) {
            $this->violation('Choose the semester of this Senior High subject.', 'semester');
        }
        if (!$grade->isSeniorHigh() && (null !== $value->semester || null !== $value->track || null !== $strand)) {
            $this->violation('Only Grade 11 and 12 subjects have a semester, track and strand.', 'semester');
        }

        // Components keep their own grade, so a parent moved to another grade would leave them behind
        foreach ($value->componentGrades as $componentGrade) {
            if ($componentGrade !== $grade) {
                $this->violation(
                    \sprintf('Move or delete the components of %s before changing its grade level.', $value->code),
                    'gradeLevel',
                );
                break;
            }
        }

        $this->validateParent($value, $scope->school, $scope->schoolYear);
    }

    private function validateParent(SubjectDetails $value, School $school, SchoolYear $schoolYear): void
    {
        $parent = null !== $value->parentId ? $this->subjects->findById($value->parentId) : null;
        if (null === $parent) {
            return;
        }
        $message = match (true) {
            $parent->getId() === $value->subjectId() => 'A subject cannot be a component of itself.',
            $parent->getSchool() !== $school || $parent->getSchoolYear() !== $schoolYear
                => 'A component must be a subject of the same school and school year.',
            $parent->getGradeLevel() !== $value->gradeLevel
                => 'A component must be in the same grade level as its subject.',
            $parent->isComponent() => 'A component cannot have components of its own.',
            $value->hasComponents => \sprintf(
                '%s – %s has components, so it cannot be a component itself.',
                $value->code,
                $value->name,
            ),
            default => null,
        };
        if (null !== $message) {
            $this->violation($message, 'parentId');
        }
    }

    private function violation(string $message, string $path): void
    {
        $this->context->buildViolation($message)->atPath($path)->addViolation();
    }
}
