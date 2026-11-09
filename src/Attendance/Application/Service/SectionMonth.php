<?php

declare(strict_types=1);

namespace App\Attendance\Application\Service;

use App\Attendance\Domain\Service\Sf2\Sf2Report;
use App\Attendance\Domain\Service\Sf2\Sf2Status;
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
