<?php

namespace App\Entity;

/** PDS item 6, as printed on CS Form 212 (Revised 2026). */
enum CivilStatus: string
{
    case Single = 'single';
    case Married = 'married';
    case Widowed = 'widowed';
    case Separated = 'separated';
    case Others = 'others';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single',
            self::Married => 'Married',
            self::Widowed => 'Widowed',
            self::Separated => 'Separated',
            self::Others => 'Other/s',
        };
    }
}
