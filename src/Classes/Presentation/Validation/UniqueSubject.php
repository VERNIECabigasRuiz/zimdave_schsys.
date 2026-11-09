<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * A subject code is used once per school year, and a subject name once per grade level
 * (and semester) in a school year.
 */
final class UniqueSubject extends Constraint
{
    public string $codeMessage = 'The code {{ code }} is already used this school year.';
    public string $nameMessage = '{{ grade }} already has a subject named {{ name }}.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
