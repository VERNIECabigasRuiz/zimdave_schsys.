<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * A section name is used once per grade level in a school year, and a teacher
 * advises at most one section per school year.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UniqueSection extends Constraint
{
    public string $nameMessage = 'This section name already exists in {{ grade }} for this school year.';
    public string $adviserMessage = '{{ teacher }} is already the adviser of {{ section }}.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
