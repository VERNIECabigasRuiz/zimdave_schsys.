<?php

declare(strict_types=1);

namespace App\Attendance\Domain\Service\Sf2;

/** Fills DepEd's SF2 spreadsheet with a report. */
interface Sf2SpreadsheetInterface
{
    /** The report as an .xlsx file's content. */
    public function xlsx(Sf2Report $report): string;
}
