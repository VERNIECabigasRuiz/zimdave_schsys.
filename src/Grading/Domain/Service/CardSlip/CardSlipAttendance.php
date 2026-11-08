<?php

declare(strict_types=1);

namespace App\Grading\Domain\Service\CardSlip;

/** A learner's attendance over the term, as the card slip prints it. */
final readonly class CardSlipAttendance
{
    public function __construct(
        public int $schoolDays,
        public int $present,
        public int $absent,
        public int $late,
    ) {
    }
}
