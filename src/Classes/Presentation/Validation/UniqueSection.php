<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * A section name is used once per grade level in a school year, and a teacher
 * advises at most one section per school year.
 */
final class UniqueSection extends Constraint
{
    public string $nameMessage = 'This section name already exists in {{ grade }} for this school year.';
    public string $adviserMessage = '{{ teacher }} is already the adviser of {{ section }}.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
