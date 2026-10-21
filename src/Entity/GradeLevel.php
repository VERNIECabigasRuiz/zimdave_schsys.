<?php

namespace App\Entity;

/** K to 12 grade levels, in order. */
enum GradeLevel: int
{
    case Kinder = 0;
    case Grade1 = 1;
    case Grade2 = 2;
    case Grade3 = 3;
    case Grade4 = 4;
    case Grade5 = 5;
    case Grade6 = 6;
    case Grade7 = 7;
    case Grade8 = 8;
    case Grade9 = 9;
    case Grade10 = 10;
    case Grade11 = 11;
    case Grade12 = 12;

    public function label(): string
    {
        return self::Kinder === $this ? 'Kindergarten' : 'Grade '.$this->value;
    }

    public function isSeniorHigh(): bool
    {
        return $this->value >= 11;
    }
}
