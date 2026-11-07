<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * A subject fits its school and grade level: meetings per week where timetables need them,
 * a grade level the school offers, a semester only (and always) in Senior High, and a
 * component only of a top-level subject of the same grade level.
 */
final class ValidSubject extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
