<?php

namespace App\Entity;

/** A school day. Classes run Monday to Friday only. */
enum Weekday: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;

    public function short(): string
    {
        return substr($this->name, 0, 3);
    }

    /** The weekday of a date, or null on a Saturday or Sunday. */
    public static function fromDate(\DateTimeImmutable $date): ?self
    {
        return self::tryFrom((int) $date->format('N'));
    }
}
