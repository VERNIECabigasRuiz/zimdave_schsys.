<?php

declare(strict_types=1);

namespace App\Learners\Domain\Model;

enum SexAtBirth: string
{
    case Male = 'male';
    case Female = 'female';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
        };
    }
}
