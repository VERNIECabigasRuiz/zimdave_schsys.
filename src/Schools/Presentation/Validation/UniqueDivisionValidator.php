<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Validation;

use App\Schools\Application\UseCase\Command\DivisionDetails;
use App\Schools\Application\UseCase\Command\UpdateDivisionCommand;
use App\Schools\Domain\Model\Repository\DivisionRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueDivisionValidator extends ConstraintValidator
{
    public function __construct(private readonly DivisionRepositoryInterface $divisions)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueDivision) {
            throw new UnexpectedTypeException($constraint, UniqueDivision::class);
        }
        if (!$value instanceof DivisionDetails) {
            return;
        }

        $region = trim($value->region);
        $name = trim($value->name);
        $owner = '' !== $region && '' !== $name ? $this->divisions->findByRegionAndName($region, $name) : null;
        $isSameDivision = $value instanceof UpdateDivisionCommand && $owner?->getId() === $value->id;
        if (null !== $owner && !$isSameDivision) {
            $this->context->buildViolation($constraint->message)->atPath('name')->addViolation();
        }
    }
}
