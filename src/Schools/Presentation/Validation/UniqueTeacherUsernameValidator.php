<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Validation;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Schools\Application\UseCase\Command\TeacherDetails;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueTeacherUsernameValidator extends ConstraintValidator
{
    public function __construct(private readonly AccountDirectoryInterface $accounts)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueTeacherUsername) {
            throw new UnexpectedTypeException($constraint, UniqueTeacherUsername::class);
        }
        if (!$value instanceof TeacherDetails) {
            return;
        }

        if ($this->accounts->isUsernameTaken($value->username, $value->accountId())) {
            $this->context->buildViolation($constraint->message)->atPath('username')->addViolation();
        }
    }
}
