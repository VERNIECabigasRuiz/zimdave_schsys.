<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * The school form's School ID is not used by another school. Checked with the other field rules
 * so every mistake shows at once.
 */
final class UniqueSchoolId extends Constraint
{
    public string $message = 'This School ID already exists.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
