<?php

namespace App\Entity;

enum DualCitizenshipBy: string
{
    case Birth = 'birth';
    case Naturalization = 'naturalization';

    public function label(): string
    {
        return match ($this) {
            self::Birth => 'by birth',
            self::Naturalization => 'by naturalization',
        };
    }
}
