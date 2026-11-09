<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * The school year form's name is not used by another school year. Checked with the other field rules
 * so every mistake shows at once.
 */
final class UniqueSchoolYearName extends Constraint
{
    public string $message = 'This school year already exists.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
