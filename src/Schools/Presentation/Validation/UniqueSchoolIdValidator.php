<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Validation;

use App\Schools\Application\UseCase\Command\SchoolDetails;
use App\Schools\Application\UseCase\Command\UpdateSchoolCommand;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueSchoolIdValidator extends ConstraintValidator
{
    public function __construct(private readonly SchoolRepositoryInterface $schools)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueSchoolId) {
            throw new UnexpectedTypeException($constraint, UniqueSchoolId::class);
        }
        if (!$value instanceof SchoolDetails) {
            return;
        }

        $schoolId = trim($value->schoolId);
        $owner = '' !== $schoolId ? $this->schools->findBySchoolId($schoolId) : null;
        $isSameSchool = $value instanceof UpdateSchoolCommand && $owner?->getId() === $value->id;
        if (null !== $owner && !$isSameSchool) {
            $this->context->buildViolation($constraint->message)->atPath('schoolId')->addViolation();
        }
    }
}
