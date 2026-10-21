<?php

namespace App\Entity;

enum SchoolType: string
{
    case Elementary = 'elementary';
    case JuniorHigh = 'junior_high';
    case SeniorHigh = 'senior_high';
    case Integrated = 'integrated';

    public function label(): string
    {
        return match ($this) {
            self::Elementary => 'Elementary',
            self::JuniorHigh => 'Junior High School',
            self::SeniorHigh => 'Senior High School',
            self::Integrated => 'Integrated School',
        };
    }

    /** @return list<GradeLevel> the grade levels this kind of school offers */
    public function gradeLevels(): array
    {
        [$from, $to] = match ($this) {
            self::Elementary => [0, 6],
            self::JuniorHigh => [7, 10],
            self::SeniorHigh => [11, 12],
            self::Integrated => [0, 12],
        };

        return array_map(GradeLevel::from(...), range($from, $to));
    }

    /** "a Junior High School", "an Elementary school" — for messages. */
    public function withArticle(): string
    {
        $label = self::Elementary === $this ? 'Elementary school' : $this->label();

        return (str_contains('AEIOU', $label[0]) ? 'an ' : 'a ').$label;
    }
}
