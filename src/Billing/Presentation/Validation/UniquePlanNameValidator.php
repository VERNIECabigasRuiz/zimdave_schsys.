<?php

declare(strict_types=1);

namespace App\Billing\Presentation\Validation;

use App\Billing\Application\UseCase\Command\CreatePlanCommand;
use App\Billing\Application\UseCase\Command\UpdatePlanCommand;
use App\Billing\Domain\Model\Repository\PlanRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniquePlanNameValidator extends ConstraintValidator
{
    public function __construct(private readonly PlanRepositoryInterface $plans)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniquePlanName) {
            throw new UnexpectedTypeException($constraint, UniquePlanName::class);
        }
        if (!$value instanceof CreatePlanCommand && !$value instanceof UpdatePlanCommand) {
            return;
        }

        $name = trim($value->name);
        $owner = '' !== $name ? $this->plans->findByName($name) : null;
        $isSamePlan = $value instanceof UpdatePlanCommand && $owner?->getId() === $value->id;
        if (null !== $owner && !$isSamePlan) {
            $this->context->buildViolation($constraint->message)->atPath('name')->addViolation();
        }
    }
}
