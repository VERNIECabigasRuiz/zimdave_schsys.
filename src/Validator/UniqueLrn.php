<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** A learner reference number belongs to one learner. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UniqueLrn extends Constraint
{
    public string $message = 'LRN {{ lrn }} belongs to {{ name }}.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
