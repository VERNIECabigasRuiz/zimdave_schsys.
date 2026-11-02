<?php

namespace App\Nutrition;

/** Nutritional status by BMI-for-age. Case order is the SF8 template's column order. */
enum BmiCategory: string
{
    case SeverelyWasted = 'severely_wasted';
    case Wasted = 'wasted';
    case Normal = 'normal';
    case Overweight = 'overweight';
    case Obese = 'obese';

    public function label(): string
    {
        return match ($this) {
            self::SeverelyWasted => 'Severely Wasted',
            self::Wasted => 'Wasted',
            self::Normal => 'Normal',
            self::Overweight => 'Overweight',
            self::Obese => 'Obese',
        };
    }
}
