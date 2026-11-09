<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Validation;

use App\Schools\Application\UseCase\Command\SchoolYearDetails;
use App\Schools\Application\UseCase\Command\UpdateSchoolYearCommand;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueSchoolYearNameValidator extends ConstraintValidator
{
    public function __construct(private readonly SchoolYearRepositoryInterface $schoolYears)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueSchoolYearName) {
            throw new UnexpectedTypeException($constraint, UniqueSchoolYearName::class);
        }
        if (!$value instanceof SchoolYearDetails) {
            return;
        }

        $name = trim($value->name);
        $owner = '' !== $name ? $this->schoolYears->findByName($name) : null;
        $isSameYear = $value instanceof UpdateSchoolYearCommand && $owner?->getId() === $value->id;
        if (null !== $owner && !$isSameYear) {
            $this->context->buildViolation($constraint->message)->atPath('name')->addViolation();
        }
    }
}
