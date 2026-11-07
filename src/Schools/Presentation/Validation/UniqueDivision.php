<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * The division form's region and division are not on the list yet. Checked with the other field rules
 * so every mistake shows at once.
 */
final class UniqueDivision extends Constraint
{
    public string $message = 'This division is already on the list.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
