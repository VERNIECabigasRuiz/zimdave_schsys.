<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Validation;

use Symfony\Component\Validator\Constraint;

/** A period of the school day does not overlap another period or break of the same day. */
final class NoOverlappingPeriod extends Constraint
{
    public string $message = 'This time overlaps {{ label }} ({{ range }}).';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
