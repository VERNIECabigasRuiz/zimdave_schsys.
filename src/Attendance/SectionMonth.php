<?php

namespace App\Attendance;

use App\Classes\Domain\Model\Section;

/** A section's month, for the principal's overview. */
final readonly class SectionMonth
{
    public function __construct(
        public Section $section,
        public Sf2Report $report,
        public int $absences,
        public int $tardies,
        public Sf2Status $status,
    ) {
    }
}
