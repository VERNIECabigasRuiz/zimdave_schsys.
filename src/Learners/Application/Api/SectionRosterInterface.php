<?php

declare(strict_types=1);

namespace App\Learners\Application\Api;

use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\TransferRequest;

/** Who belongs to a section on which days, as the school forms (SF2, SF8) and card slips count them. */
interface SectionRosterInterface
{
    /**
     * The section's class list for the month, plus the learners who transferred out of it during or
     * after the month, each with the days they count.
     *
     * @return list<RosterEntry> males first, then females, each by name; only those who count on some day of the month
     */
    public function forMonth(Section $section, \DateTimeImmutable $month): array;

    /**
     * The section's class list today.
     *
     * @return list<Enrollment> boys first, then girls; each by last, first and middle name
     */
    public function classList(Section $section): array;

    /**
     * The approved transfers out of the section decided on or after $since.
     *
     * @return list<TransferRequest>
     */
    public function transfersOutSince(Section $section, \DateTimeImmutable $since): array;
}
