<?php

namespace App\Validator;

use App\Entity\Subject;
use App\Repository\SubjectRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueSubjectValidator extends ConstraintValidator
{
    public function __construct(private readonly SubjectRepository $subjects)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueSubject) {
            throw new UnexpectedTypeException($constraint, UniqueSubject::class);
        }
        if (!$value instanceof Subject) {
            return;
        }

        if ('' !== $value->getCode()) {
            $same = $this->subjects->findOneBy(['school' => $value->getSchool(), 'schoolYear' => $value->getSchoolYear(), 'code' => $value->getCode()]);
            if (null !== $same && $same !== $value) {
                $this->context->buildViolation($constraint->codeMessage)
                    ->setParameter('{{ code }}', $value->getCode())
                    ->atPath('code')
                    ->addViolation();
            }
        }

        // findOneBy turns a null semester into IS NULL, so K–10 names are compared too
        if (null !== $value->getGradeLevel() && '' !== $value->getName()) {
            $same = $this->subjects->findOneBy([
                'school' => $value->getSchool(),
                'schoolYear' => $value->getSchoolYear(),
                'gradeLevel' => $value->getGradeLevel(),
                'semester' => $value->getSemester(),
                'name' => $value->getName(),
            ]);
            if (null !== $same && $same !== $value) {
                $this->context->buildViolation($constraint->nameMessage)
                    ->setParameter('{{ grade }}', $value->getGradeLevel()->label())
                    ->setParameter('{{ name }}', $same->getName())
                    ->atPath('name')
                    ->addViolation();
            }
        }
    }
}
