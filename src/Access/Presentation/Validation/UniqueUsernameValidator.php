<?php

declare(strict_types=1);

namespace App\Access\Presentation\Validation;

use App\Access\Application\UseCase\Command\CreateAccountCommand;
use App\Access\Application\UseCase\Command\UpdateAccountCommand;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueUsernameValidator extends ConstraintValidator
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueUsername) {
            throw new UnexpectedTypeException($constraint, UniqueUsername::class);
        }
        if (!$value instanceof CreateAccountCommand && !$value instanceof UpdateAccountCommand) {
            return;
        }

        $owner = '' !== trim($value->username) ? $this->users->findOneByUsername($value->username) : null;
        $isSameAccount = $value instanceof UpdateAccountCommand && $owner?->getId() === $value->id;
        if (null !== $owner && !$isSameAccount) {
            $this->context->buildViolation($constraint->message)->atPath('username')->addViolation();
        }
    }
}
