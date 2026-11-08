<?php

declare(strict_types=1);

namespace App\Attendance\Application\Service;

use App\Classes\Domain\Model\Section;

/** A section's attendance today, for the principal's overview. */
final readonly class SectionToday
{
    public function __construct(
        public Section $section,
        public bool $taken,
        public int $enrolled,
        public int $present,
        public int $absent,
        public int $tardy,
        public ?string $adviserName,
    ) {
    }
}
