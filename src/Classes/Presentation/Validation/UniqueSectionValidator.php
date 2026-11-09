<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Validation;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Classes\Application\UseCase\Command\SectionDetails;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueSectionValidator extends ConstraintValidator
{
    public function __construct(
        private readonly SectionRepositoryInterface $sections,
        private readonly SchoolDirectoryInterface $schools,
        private readonly AccountDirectoryInterface $accounts,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueSection) {
            throw new UnexpectedTypeException($constraint, UniqueSection::class);
        }
        $school = $value instanceof SectionDetails ? $this->schools->findSchool($value->schoolId) : null;
        $schoolYear = $this->schools->currentYear();
        if (!$value instanceof SectionDetails || null === $school || null === $schoolYear) {
            return;
        }

        if (null !== $value->gradeLevel && '' !== $value->name) {
            $same = $this->sections->findOneByName($school, $schoolYear, $value->gradeLevel, $value->name);
            if (null !== $same && $same->getId() !== $value->sectionId()) {
                $this->context->buildViolation($constraint->nameMessage)
                    ->setParameter('{{ grade }}', $value->gradeLevel->label())
                    ->atPath('name')
                    ->addViolation();
            }
        }

        $adviser = null !== $value->adviserId ? $this->accounts->findById($value->adviserId) : null;
        if (null !== $adviser) {
            $advisory = $this->sections->findAdvisory($adviser, $schoolYear);
            if (null !== $advisory && $advisory->getId() !== $value->sectionId()) {
                $this->context->buildViolation($constraint->adviserMessage)
                    ->setParameter('{{ teacher }}', $adviser->getFullName())
                    ->setParameter('{{ section }}', $advisory->getLabel())
                    ->atPath('adviserId')
                    ->addViolation();
            }
        }
    }
}
