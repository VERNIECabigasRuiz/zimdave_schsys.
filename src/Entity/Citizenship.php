<?php

namespace App\Entity;

enum Citizenship: string
{
    case Filipino = 'filipino';
    case Dual = 'dual';

    public function label(): string
    {
        return match ($this) {
            self::Filipino => 'Filipino',
            self::Dual => 'Dual Citizenship',
        };
    }
}
