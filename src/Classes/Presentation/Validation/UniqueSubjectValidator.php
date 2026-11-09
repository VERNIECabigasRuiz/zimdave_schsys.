<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Validation;

use App\Classes\Application\Service\Subject\SubjectScopeResolver;
use App\Classes\Application\UseCase\Command\SubjectDetails;
use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueSubjectValidator extends ConstraintValidator
{
    public function __construct(
        private readonly SubjectRepositoryInterface $subjects,
        private readonly SubjectScopeResolver $scopes,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueSubject) {
            throw new UnexpectedTypeException($constraint, UniqueSubject::class);
        }
        if (!$value instanceof SubjectDetails) {
            return;
        }
        $scope = $this->scopes->resolve($value->scope);

        if ('' !== $value->code) {
            $same = $this->subjects->findOneByCode($scope->school, $scope->schoolYear, $value->code);
            if (null !== $same && $same->getId() !== $value->subjectId()) {
                $this->context->buildViolation($constraint->codeMessage)
                    ->setParameter('{{ code }}', $value->code)
                    ->atPath('code')
                    ->addViolation();
            }
        }

        if (null !== $value->gradeLevel && '' !== $value->name) {
            $same = $this->subjects->findOneByGradeAndName(
                $scope->school,
                $scope->schoolYear,
                $value->gradeLevel,
                $value->semester,
                $value->name,
            );
            if (null !== $same && $same->getId() !== $value->subjectId()) {
                $this->context->buildViolation($constraint->nameMessage)
                    ->setParameter('{{ grade }}', $value->gradeLevel->label())
                    ->setParameter('{{ name }}', $same->getName())
                    ->atPath('name')
                    ->addViolation();
            }
        }
    }
}
