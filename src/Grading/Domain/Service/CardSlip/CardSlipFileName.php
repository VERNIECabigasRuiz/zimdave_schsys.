<?php

declare(strict_types=1);

namespace App\Grading\Domain\Service\CardSlip;

use App\Classes\Domain\Model\Section;
use App\Schools\Domain\Model\GradingPeriod;

/** The download names of card slip PDFs. */
final class CardSlipFileName
{
    /** "Card slips - Grade 7 ACTIVE - Term 1.pdf" */
    public static function forSection(Section $section, GradingPeriod $period): string
    {
        $gradeSection = trim(($section->getGradeLevel()?->label() ?? '') . ' ' . mb_strtoupper($section->getName()));

        return \sprintf('Card slips - %s - %s.pdf', self::safe($gradeSection), $period->getLabel());
    }

    /** "Card slip - DELA CRUZ, JUAN - Term 1.pdf" */
    public static function forSlip(CardSlip $slip): string
    {
        return \sprintf('Card slip - %s - %s.pdf', self::safe($slip->learnerName), $slip->periodLabel);
    }

    /** "/", "\" and "%" become "-", a download filename can't have them (as the SF1 and ID filenames do) */
    private static function safe(string $text): string
    {
        return (string) preg_replace('/[\/\\\\%]/', '-', $text);
    }
}
