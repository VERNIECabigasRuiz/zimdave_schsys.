<?php

namespace App\Attendance;

use App\Entity\Enrollment;

/** A learner on a section's SF2 and the days they count there: $from to $until, both included. */
final readonly class RosterEntry
{
    public function __construct(
        public Enrollment $enrollment,
        public bool $male,
        public string $name,
        public \DateTimeImmutable $from,
        public ?\DateTimeImmutable $until,
        public bool $lateEnrollment,
        public bool $transferredIn,
        public bool $transferredOut,
        public bool $dropped,
        public bool $undatedDrop,
        public ?string $remark,
    ) {
    }

    public function counts(\DateTimeImmutable $day): bool
    {
        $day = $day->setTime(0, 0);

        return $day >= $this->from && (null === $this->until || $day <= $this->until);
    }
}
