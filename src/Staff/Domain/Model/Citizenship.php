<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model;

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
