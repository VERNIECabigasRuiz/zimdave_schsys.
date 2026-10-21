<?php

namespace App\Entity;

/** Senior High School tracks (old curriculum names and the strengthened SHS "TechPro"). */
enum ShsTrack: string
{
    case Academic = 'academic';
    case TvlTechPro = 'tvl_techpro';
    case Sports = 'sports';
    case ArtsDesign = 'arts_design';

    public function label(): string
    {
        return match ($this) {
            self::Academic => 'Academic',
            self::TvlTechPro => 'TVL / TechPro',
            self::Sports => 'Sports',
            self::ArtsDesign => 'Arts and Design',
        };
    }
}
