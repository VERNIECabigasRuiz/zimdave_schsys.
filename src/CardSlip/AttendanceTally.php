<?php

namespace App\CardSlip;

/** A learner's attendance over a term, counted as SF2 does: tardies (late) include cutting class. */
final readonly class AttendanceTally
{
    public function __construct(
        public int $schoolDays,
        public int $present,
        public int $absent,
        public int $late,
    ) {
    }
}
