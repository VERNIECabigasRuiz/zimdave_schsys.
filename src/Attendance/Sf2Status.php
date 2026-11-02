<?php

namespace App\Attendance;

/** Where a section's SF2 for a month stands; it is due on the 5th of the next month. */
enum Sf2Status: string
{
    case NotSubmitted = 'not_submitted';
    case Submitted = 'submitted';
    case Late = 'late';

    public function label(): string
    {
        return match ($this) {
            self::NotSubmitted => 'Not submitted',
            self::Submitted => 'Submitted',
            self::Late => 'Late',
        };
    }
}
