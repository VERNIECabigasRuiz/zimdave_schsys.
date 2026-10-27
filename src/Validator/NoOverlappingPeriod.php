<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** A school day slot cannot overlap another slot of the same school year (touching is fine). */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class NoOverlappingPeriod extends Constraint
{
    public string $message = 'This time overlaps {{ label }} ({{ range }}).';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
