<?php

declare(strict_types=1);

namespace App\Billing\Presentation\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * The plan form's name is not used by another plan. Checked with the other field rules
 * so every mistake shows at once.
 */
final class UniquePlanName extends Constraint
{
    public string $message = 'This plan already exists.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
