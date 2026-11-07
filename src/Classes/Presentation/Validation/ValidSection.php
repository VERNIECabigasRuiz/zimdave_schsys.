<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * A section's grade level is one the school offers, and only Grade 11 and 12 sections
 * have a track (which they need) and a strand.
 */
final class ValidSection extends Constraint
{
    public string $seniorHighTrackMessage = 'Choose the track of this Senior High section.';
    public string $trackOnlyForSeniorHighMessage = 'Only Grade 11 and 12 sections have a track and strand.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
