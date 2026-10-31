<?php

namespace App\Entity;

enum GradeChangeStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::Withdrawn => 'Withdrawn',
        };
    }
}
