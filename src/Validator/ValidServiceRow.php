<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** A service record row's dates make sense on their own and against the employee's other rows. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidServiceRow extends Constraint
{
    public string $endBeforeStart = 'The end date is before the start date.';
    public string $lwop = 'Give both leave without pay dates, the end not before the start.';
    public string $overlap = 'This service overlaps the row from {{ from }}.';
    public string $secondOpen = 'There is already a row up to the present. Close it first.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
