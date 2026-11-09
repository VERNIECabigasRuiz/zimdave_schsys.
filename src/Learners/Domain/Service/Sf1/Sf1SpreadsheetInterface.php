<?php

declare(strict_types=1);

namespace App\Learners\Domain\Service\Sf1;

/** Lays out an SF1 report as the School Register spreadsheet. */
interface Sf1SpreadsheetInterface
{
    /** The report as an .xls file's content. */
    public function xls(Sf1Report $report): string;
}
