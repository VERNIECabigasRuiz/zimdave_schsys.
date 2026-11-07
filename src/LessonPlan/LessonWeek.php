<?php

namespace App\LessonPlan;

use App\Entity\LessonPlan;
use App\Classes\Domain\Model\TeachingLoad;
use App\Classes\Domain\Model\Weekday;
use App\Classes\Domain\Model\Repository\ScheduleEntryRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolDayOffRepositoryInterface;

/**
 * The days of a lesson plan week: the weekdays the class meets in the timetable (for the semester the week falls
 * in), or, without a timetable, the subject's meetings per week from Monday. A school day off stays as a holiday.
 */
class LessonWeek
{
    public function __construct(
        private readonly ScheduleEntryRepositoryInterface $entries,
        private readonly SchoolDayOffRepositoryInterface $daysOff,
    ) {
    }

    public static function monday(\DateTimeImmutable $any): \DateTimeImmutable
    {
        $day = $any->setTime(0, 0);

        return $day->modify('-'.((int) $day->format('N') - 1).' days');
    }

    /** A new plan for the week, its days set and every cell empty. */
    public function start(TeachingLoad $load, \DateTimeImmutable $weekStart, \DateTimeImmutable $now): LessonPlan
    {
        $monday = self::monday($weekStart);
        $days = array_map(static fn (array $day) => LessonPlan::emptyDay($day['date'], $day['holiday']), $this->days($load, $monday));

        return (new LessonPlan($load, $monday, $now))->setDays($days);
    }

    /** @return list<array{date: string, holiday: ?string}> in date order */
    public function days(TeachingLoad $load, \DateTimeImmutable $weekStart): array
    {
        $monday = self::monday($weekStart);
        $weekdays = $this->meetingDays($load, $monday);
        $section = $load->getSection();
        $daysOff = $this->daysOff->findBetween($section->getSchool(), $monday, $monday->modify('+4 days'));

        $days = [];
        foreach ($weekdays as $weekday) {
            $date = $monday->modify('+'.($weekday->value - 1).' days')->format('Y-m-d');
            $days[] = ['date' => $date, 'holiday' => isset($daysOff[$date]) ? $daysOff[$date]->getReason() : null];
        }

        return $days;
    }

    /** @return list<Weekday> Monday first */
    private function meetingDays(TeachingLoad $load, \DateTimeImmutable $monday): array
    {
        $semester = $this->semesterAt($load, $monday);
        $weekdays = [];
        foreach ($this->entries->findForLoad($load) as $entry) {
            if (0 === $entry->getTerm() || $entry->getTerm() === $semester) {
                $weekdays[$entry->getDay()->value] = $entry->getDay();
            }
        }
        if ([] === $weekdays) {
            $count = min(5, max(1, $load->getSubject()->getMeetingsPerWeek() ?? 5));

            return \array_slice(Weekday::cases(), 0, $count);
        }
        ksort($weekdays);

        return array_values($weekdays);
    }

    /** Semester 1 before the school year's midpoint, 2 from then on (as the timetable counts it). */
    private function semesterAt(TeachingLoad $load, \DateTimeImmutable $at): int
    {
        $schoolYear = $load->getSection()->getSchoolYear();
        $start = $schoolYear->getStartDate()->getTimestamp();
        $midpoint = $start + intdiv($schoolYear->getEndDate()->getTimestamp() - $start, 2);

        return $at->getTimestamp() < $midpoint ? 1 : 2;
    }
}
