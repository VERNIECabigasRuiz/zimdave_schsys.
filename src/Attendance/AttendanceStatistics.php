<?php

namespace App\Attendance;

use App\Entity\LearnerMark;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Sf2Submission;
use App\Repository\RollCallRepository;
use App\Repository\SectionRepository;
use App\Repository\Sf2SubmissionRepository;

/** The school's attendance at a glance, for the principal: today, a month, and who needs a follow-up. */
class AttendanceStatistics
{
    /** SF2 is due on this day of the next month. */
    private const DUE_DAY = 5;

    public function __construct(
        private readonly SectionRepository $sections,
        private readonly RollCallRepository $rollCalls,
        private readonly Sf2SubmissionRepository $submissions,
        private readonly Sf2Roster $roster,
        private readonly Sf2ReportBuilder $reports,
        private readonly SchoolCalendar $calendar,
        private readonly AttendanceRules $rules,
    ) {
    }

    /** @return array{schoolDay: bool, reason: ?string, sections: list<SectionToday>} */
    public function today(School $school, SchoolYear $schoolYear): array
    {
        $today = $this->rules->today();
        if (!$this->calendar->isSchoolDay($school, $schoolYear, $today)) {
            $reason = $this->calendar->dayOff($school, $today)?->getReason() ?? ((int) $today->format('N') > 5 ? 'Weekend' : 'Outside the school year');

            return ['schoolDay' => false, 'reason' => $reason, 'sections' => []];
        }

        $rollCalls = $this->rollCalls->findForSchoolOn($school, $schoolYear, $today);
        $sections = [];
        foreach ($this->sections->findForSchool($school, $schoolYear) as $section) {
            $rollCall = $rollCalls[$section->getId()] ?? null;
            $enrolled = $absent = $tardy = 0;
            foreach ($this->roster->forMonth($section, $today) as $entry) {
                if (!$entry->counts($today)) {
                    continue;
                }
                ++$enrolled;
                $mark = $rollCall?->markFor($entry->enrollment);
                $absent += LearnerMark::Absent === $mark ? 1 : 0;
                $tardy += $mark?->isTardy() ? 1 : 0;
            }
            $sections[] = new SectionToday($section, null !== $rollCall, $enrolled, $enrolled - $absent, $absent, $tardy, $section->getAdviser()?->getFullName());
        }

        return ['schoolDay' => true, 'reason' => null, 'sections' => $sections];
    }

    /** @return list<SectionMonth> by grade level, then name */
    public function month(School $school, SchoolYear $schoolYear, \DateTimeImmutable $month): array
    {
        $month = $month->modify('first day of this month')->setTime(0, 0);
        $submissions = $this->submissions->findForMonth($school, $schoolYear, $month);

        return array_map(function ($section) use ($month, $submissions): SectionMonth {
            $report = $this->reports->build($section, $month);

            return new SectionMonth(
                $section,
                $report,
                array_sum(array_map(static fn (Sf2Row $r) => $r->absences, $report->rows())),
                array_sum(array_map(static fn (Sf2Row $r) => $r->tardies, $report->rows())),
                $this->status($month, $submissions[$section->getId()] ?? null),
            );
        }, $this->sections->findForSchool($school, $schoolYear));
    }

    /**
     * @param list<SectionMonth> $months
     *
     * @return list<array{section: \App\Entity\Section, row: Sf2Row}> learners absent 5 school days in a row
     */
    public function followUps(array $months): array
    {
        $followUps = [];
        foreach ($months as $sectionMonth) {
            foreach ($sectionMonth->report->rows() as $row) {
                if ($row->fiveConsecutive) {
                    $followUps[] = ['section' => $sectionMonth->section, 'row' => $row];
                }
            }
        }

        return $followUps;
    }

    /** Late once the 5th of the next month has passed without a submission, or when submitted after it. */
    public function status(\DateTimeImmutable $month, ?Sf2Submission $submission): Sf2Status
    {
        $due = $month->modify('first day of next month')->setTime(0, 0)->modify('+'.(self::DUE_DAY - 1).' days');
        if (null !== $submission) {
            return $submission->getSubmittedAt()->setTime(0, 0) > $due ? Sf2Status::Late : Sf2Status::Submitted;
        }

        return $this->rules->today() > $due ? Sf2Status::Late : Sf2Status::NotSubmitted;
    }
}
