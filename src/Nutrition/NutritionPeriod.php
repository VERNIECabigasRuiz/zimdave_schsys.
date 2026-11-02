<?php

namespace App\Nutrition;

/** The two weighings of a school year. */
enum NutritionPeriod: string
{
    case Baseline = 'baseline';
    case Endline = 'endline';

    public function label(): string
    {
        return match ($this) {
            self::Baseline => 'Baseline',
            self::Endline => 'Endline',
        };
    }
}
