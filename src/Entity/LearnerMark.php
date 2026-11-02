<?php

namespace App\Entity;

/** A learner's attendance on a school day other than present; present is the absence of a mark. */
enum LearnerMark: string
{
    case Absent = 'absent';
    case Late = 'late';
    case Cutting = 'cutting';

    public function label(): string
    {
        return match ($this) {
            self::Absent => 'Absent',
            self::Late => 'Late',
            self::Cutting => 'Cutting classes',
        };
    }

    /** Late and cutting classes count as present, and as tardy. */
    public function isTardy(): bool
    {
        return self::Absent !== $this;
    }

    public function code(): string
    {
        return match ($this) {
            self::Absent => 'x',
            self::Late => 'L',
            self::Cutting => 'C',
        };
    }
}
