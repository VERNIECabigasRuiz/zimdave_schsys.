<?php

declare(strict_types=1);

namespace App\Learners\Presentation\Validation;

use Symfony\Component\Validator\Constraint;

/** The LRN of the learner details must not belong to another learner. */
final class UniqueLrn extends Constraint
{
    public string $message = 'LRN {{ lrn }} belongs to {{ name }}.';
}
