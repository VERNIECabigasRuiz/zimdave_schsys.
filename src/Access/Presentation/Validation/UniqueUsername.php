<?php

declare(strict_types=1);

namespace App\Access\Presentation\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * The account form's username is not used by another account. Checked with the other
 * field rules so every mistake shows at once.
 */
final class UniqueUsername extends Constraint
{
    public string $message = 'This username is already taken.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
