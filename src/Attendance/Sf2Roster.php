<?php

namespace App\Attendance;

use App\Entity\Enrollment;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Sf1Remark;
use App\Repository\EnrollmentRepository;
use App\Repository\TransferRequestRepository;
use App\Sf1\Sf1Remarks;

/**
 * The learners of a section's SF2 for a month: its class list, plus the learners who
 * transferred out of it during or after the month, each with the days they count.
 */
class Sf2Roster
{
    public function __construct(
        private readonly EnrollmentRepository $enrollments,
        private readonly TransferRequestRepository $transfers,
    ) {
    }

    /** @return list<RosterEntry> males first, then females, each by name; only those who count on some day of the month */
    public function forMonth(Section $section, \DateTimeImmutable $month): array
    {
        $first = $month->modify('first day of this month')->setTime(0, 0);
        $last = $first->modify('last day of this month');
        $schoolYear = $section->getSchoolYear();
        $start = $schoolYear->getStartDate()?->setTime(0, 0) ?? $first;

        $transfersIn = $this->transfers->findApprovedIntoSection($section);
        $entries = [];
        foreach ($this->enrollments->findClassList($section) as $enrollment) {
            $transferIn = $transfersIn[$enrollment->getId()] ?? null;
            $transferredIn = null !== $transferIn && null !== $transferIn->getDecidedAt();
            // DepEd's late enrollee is the one with SF1's LE remark; others count from the first day, however late they were encoded
            $lateEnrollment = !$transferredIn && Sf1Remark::LateEnrollment === $enrollment->getRemarkCode();
            $from = match (true) {
                $transferredIn => $transferIn->getDecidedAt()->setTime(0, 0),
                $lateEnrollment => max(($enrollment->getRemarkDate() ?? $enrollment->getEnrolledAt())->setTime(0, 0), $start),
                default => $start,
            };
            $dropped = Sf1Remark::Dropped === $enrollment->getRemarkCode();
            $dropDate = $dropped ? $enrollment->getRemarkDate()?->setTime(0, 0) : null;

            $entries[] = $this->entry(
                $enrollment, $from, $dropDate?->modify('-1 day'),
                lateEnrollment: $lateEnrollment,
                transferredIn: $transferredIn,
                transferredOut: false,
                dropped: $dropped,
                undatedDrop: $dropped && null === $dropDate,
                // as Sf1Remarks::textFor(), without a query per learner
                remark: $dropped ? trim('DRP '.($enrollment->getRemarkDetails() ?? '')) : ($enrollment->getManualRemarkText() ?? (null !== $transferIn ? Sf1Remarks::transferIn($transferIn) : null)),
            );
        }

        foreach ($this->transfers->findApprovedOutOf($section, $first) as $transfer) {
            $enrollment = $transfer->getEnrollment();
            if (null === $enrollment || null === $transfer->getDecidedAt()) {
                continue;
            }
            $entries[] = $this->entry(
                $enrollment, $start, $transfer->getDecidedAt()->setTime(0, 0)->modify('-1 day'),
                lateEnrollment: false,
                transferredIn: false,
                transferredOut: true,
                dropped: false,
                undatedDrop: false,
                remark: 'T/O '.$transfer->getToSchool()->getName(),
            );
        }

        $entries = array_filter($entries, static fn (RosterEntry $e) => $e->from <= $last && (null === $e->until || $e->until >= $first));
        usort($entries, static fn (RosterEntry $a, RosterEntry $b) => [$b->male, mb_strtoupper($a->name)] <=> [$a->male, mb_strtoupper($b->name)]);

        return array_values($entries);
    }

    private function entry(
        Enrollment $enrollment,
        \DateTimeImmutable $from,
        ?\DateTimeImmutable $until,
        bool $lateEnrollment,
        bool $transferredIn,
        bool $transferredOut,
        bool $dropped,
        bool $undatedDrop,
        ?string $remark,
    ): RosterEntry {
        $learner = $enrollment->getLearner();

        return new RosterEntry(
            $enrollment,
            SexAtBirth::Male === $learner->getSex(),
            $learner->getSf1Name(),
            $from,
            $until,
            $lateEnrollment,
            $transferredIn,
            $transferredOut,
            $dropped,
            $undatedDrop,
            $remark,
        );
    }
}
