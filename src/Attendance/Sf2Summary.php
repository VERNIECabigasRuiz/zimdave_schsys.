<?php

namespace App\Attendance;

/** The "Summary for the Month" box of SF2; every figure is [male, female, total]. */
final readonly class Sf2Summary
{
    /**
     * @param array{int, int, int}|null          $enrolmentAtCutOff null for a month that ends before the cut-off
     * @param array{int, int, int}               $lateEnrollment
     * @param array{int, int, int}               $registered
     * @param array{?float, ?float, ?float}      $percentEnrolment
     * @param array{?float, ?float, ?float}      $ada
     * @param array{?float, ?float, ?float}      $percentAttendance
     * @param array{int, int, int}               $consecutiveAbsent
     * @param array{int, int, int}               $dropped
     * @param array{int, int, int}               $transferredOut
     * @param array{int, int, int}               $transferredIn
     */
    public function __construct(
        public int $daysOfClasses,
        public ?array $enrolmentAtCutOff,
        public array $lateEnrollment,
        public array $registered,
        public array $percentEnrolment,
        public array $ada,
        public array $percentAttendance,
        public array $consecutiveAbsent,
        public array $dropped,
        public array $transferredOut,
        public array $transferredIn,
    ) {
    }
}
