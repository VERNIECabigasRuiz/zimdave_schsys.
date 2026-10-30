<?php

namespace App\Nutrition;

/** Where a section's SF8 for a weighing period stands. */
enum Sf8Status: string
{
    case NotStarted = 'not_started';
    case Draft = 'draft';
    case Submitted = 'submitted';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
        };
    }
}
