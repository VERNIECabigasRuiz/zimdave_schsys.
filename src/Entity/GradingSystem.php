<?php

namespace App\Entity;

/**
 * How a school year is divided for grading. DepEd moved to three terms from SY 2026-2027 (DO 9, s. 2026).
 */
enum GradingSystem: string
{
    case Terms = 'terms';
    case Quarters = 'quarters';

    public function periodCount(): int
    {
        return match ($this) {
            self::Terms => 3,
            self::Quarters => 4,
        };
    }

    public function periodName(): string
    {
        return match ($this) {
            self::Terms => 'Term',
            self::Quarters => 'Quarter',
        };
    }

    public function label(): string
    {
        return \sprintf('%ss (%d)', $this->periodName(), $this->periodCount());
    }
}
