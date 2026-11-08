<?php

declare(strict_types=1);

namespace App\Learners\Application\Api;

use App\Classes\Domain\Model\Section;

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
}
