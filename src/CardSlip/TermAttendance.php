<?php

namespace App\CardSlip;

use App\Attendance\Application\Api\AttendanceDirectoryInterface;
use App\Attendance\Application\Api\AttendanceTally;
use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Schools\Domain\Model\GradingPeriod;

/**
 * A section's attendance over a grading term, counted as SF2 counts its months: only the school
 * days a learner is on the roster, up to today; a school day without a roll call counts as present.
 */
class TermAttendance
{
    public function __construct(
        private readonly AttendanceDirectoryInterface $attendance,
        private readonly EnrollmentRepositoryInterface $enrollments,
    ) {
    }

    /**
     * @return array<int, ?AttendanceTally> by enrollment id; null for everyone when the term has no
     *                                      start or end date; learners on none of the term's rosters are left out
     */
    public function forSection(Section $section, GradingPeriod $period): array
    {
        $start = $period->getStartDate();
        $end = $period->getEndDate();
        if (null === $start || null === $end) {
            $result = [];
            foreach ($this->enrollments->findClassList($section) as $enrollment) {
                $result[(int) $enrollment->getId()] = null;
            }

            return $result;
        }

        return $this->attendance->tallies($section, $start, $end);
    }
}
