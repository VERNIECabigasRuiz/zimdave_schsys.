<?php

declare(strict_types=1);

namespace App\Staff\Domain\Service\StaffId;

/** The school's two ID templates */
enum StaffIdSize: string
{
    /** 100 × 138 mm, front only, on long bond */
    case Big = 'big';

    /** CR80 (54 × 86 mm), front and back side by side on letter */
    case Small = 'small';

    public function label(): string
    {
        return match ($this) {
            self::Big => 'Big',
            self::Small => 'Small',
        };
    }
}
