<?php

namespace App\Validator;

use App\Entity\Section;
use App\Repository\SectionRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueSectionValidator extends ConstraintValidator
{
    public function __construct(private readonly SectionRepository $sections)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueSection) {
            throw new UnexpectedTypeException($constraint, UniqueSection::class);
        }
        if (!$value instanceof Section) {
            return;
        }

        if (null !== $value->getGradeLevel() && '' !== $value->getName()) {
            $same = $this->sections->findOneBy([
                'school' => $value->getSchool(),
                'schoolYear' => $value->getSchoolYear(),
                'gradeLevel' => $value->getGradeLevel(),
                'name' => $value->getName(),
            ]);
            if (null !== $same && $same !== $value) {
                $this->context->buildViolation($constraint->nameMessage)
                    ->setParameter('{{ grade }}', $value->getGradeLevel()->label())
                    ->atPath('name')
                    ->addViolation();
            }
        }

        if (null !== $adviser = $value->getAdviser()) {
            $advisory = $this->sections->findAdvisory($adviser, $value->getSchoolYear());
            if (null !== $advisory && $advisory !== $value) {
                $this->context->buildViolation($constraint->adviserMessage)
                    ->setParameter('{{ teacher }}', $adviser->getFullName())
                    ->setParameter('{{ section }}', $advisory->getLabel())
                    ->atPath('adviser')
                    ->addViolation();
            }
        }
    }
}
