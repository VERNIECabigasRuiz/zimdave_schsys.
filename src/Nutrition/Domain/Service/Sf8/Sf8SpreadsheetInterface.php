<?php

declare(strict_types=1);

namespace App\Nutrition\Domain\Service\Sf8;

/** Fills DepEd's SF8 spreadsheet with a report. */
interface Sf8SpreadsheetInterface
{
    /** The report as an .xlsx file's content. */
    public function xlsx(Sf8Report $report): string;
}
