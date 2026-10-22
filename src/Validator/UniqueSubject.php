<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * A subject code is used once per school year in a school, and a subject name once
 * per grade level (and semester, for Senior High).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UniqueSubject extends Constraint
{
    public string $codeMessage = 'The code {{ code }} is already used this school year.';
    public string $nameMessage = '{{ grade }} already has a subject named {{ name }}.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
