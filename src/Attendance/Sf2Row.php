<?php

namespace App\Attendance;

/** One learner's line of SF2: a cell per school day, then the month's totals and remarks. */
final readonly class Sf2Row
{
    public const PRESENT = '';
    public const ABSENT = 'x';
    public const LATE = 'L';
    public const CUTTING = 'C';
    /** Not enrolled yet, or no longer, on that day. */
    public const NOT_COUNTED = '-';

    /** @param list<string> $cells one of the constants per school day */
    public function __construct(
        public int $enrollmentId,
        public int $learnerId,
        public string $name,
        public bool $male,
        public array $cells,
        public int $absences,
        public int $tardies,
        public ?string $remark,
        public bool $fiveConsecutive,
        public bool $undatedDrop,
    ) {
    }
}
