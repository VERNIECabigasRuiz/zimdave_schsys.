<?php

namespace App\Grading;

/** Where a class's grades in a subject stand for a grading period. */
enum ClassGradeStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Locked = 'locked';
    case Unlocked = 'unlocked';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Locked => 'Locked',
            self::Unlocked => 'Unlocked for corrections',
        };
    }
}
