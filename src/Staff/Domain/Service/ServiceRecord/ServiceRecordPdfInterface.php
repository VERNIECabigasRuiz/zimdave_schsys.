<?php

declare(strict_types=1);

namespace App\Staff\Domain\Service\ServiceRecord;

/** The DepEd service record as a PDF on long bond. */
interface ServiceRecordPdfInterface
{
    /** The PDF's content. */
    public function render(ServiceRecordPrintout $printout): string;

    /** The HTML the PDF is made from. */
    public function html(ServiceRecordPrintout $printout): string;
}
