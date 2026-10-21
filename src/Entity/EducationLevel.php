<?php

namespace App\Entity;

/** PDS item 26 levels, in the order the form lists them. */
enum EducationLevel: string
{
    case Elementary = 'elementary';
    case Secondary = 'secondary';
    case Vocational = 'vocational';
    case College = 'college';
    case Graduate = 'graduate';

    public function label(): string
    {
        return match ($this) {
            self::Elementary => 'Elementary',
            self::Secondary => 'Secondary',
            self::Vocational => 'Vocational / Trade Course',
            self::College => 'College',
            self::Graduate => 'Graduate Studies',
        };
    }

    /** Label as printed on the form. */
    public function formLabel(): string
    {
        return mb_strtoupper($this->label());
    }

    public function order(): int
    {
        return array_search($this, self::cases(), true);
    }
}
