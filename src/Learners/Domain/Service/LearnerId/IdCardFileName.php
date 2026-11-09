<?php

declare(strict_types=1);

namespace App\Learners\Domain\Service\LearnerId;

use App\Classes\Domain\Model\Section;

/** The file names of ID card downloads. */
final class IdCardFileName
{
    /** "IDs_2025_Grade 8 - ARTISTIC.pdf"; "/", "\" and "%" become "-", a download filename can't have them */
    public static function forSection(Section $section): string
    {
        $schoolYear = $section->getSchoolYear();
        $startYear = $schoolYear->getStartDate()?->format('Y') ?? substr($schoolYear->getName(), 0, 4);

        return \sprintf(
            'IDs_%s_%s - %s.pdf',
            $startYear,
            $section->getGradeLevel()?->label() ?? '',
            preg_replace('/[\/\\\\%]/', '-', mb_strtoupper($section->getName())),
        );
    }

    /** "ID_120017170001.pdf" */
    public static function forLearner(string $lrn): string
    {
        return 'ID_' . $lrn . '.pdf';
    }
}
