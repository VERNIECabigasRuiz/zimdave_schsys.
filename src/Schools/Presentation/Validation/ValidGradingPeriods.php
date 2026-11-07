<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * The school year form's period dates are filled in, in order and within the school year.
 */
final class ValidGradingPeriods extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
