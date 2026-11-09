<?php

declare(strict_types=1);

namespace App\Nutrition\Domain\Service\Sf8;

/** Height-for-age. Case order is the SF8 template's column order. */
enum HfaCategory: string
{
    case SeverelyStunted = 'severely_stunted';
    case Stunted = 'stunted';
    case Normal = 'normal';
    case Tall = 'tall';

    public function label(): string
    {
        return match ($this) {
            self::SeverelyStunted => 'Severely Stunted',
            self::Stunted => 'Stunted',
            self::Normal => 'Normal',
            self::Tall => 'Tall',
        };
    }
}
