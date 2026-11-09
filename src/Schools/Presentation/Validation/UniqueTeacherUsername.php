<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * The teacher form's username is not used by another account. Checked with the other field rules
 * so every mistake shows at once.
 */
final class UniqueTeacherUsername extends Constraint
{
    public string $message = 'This username is already taken.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
