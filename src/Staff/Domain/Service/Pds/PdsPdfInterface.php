<?php

declare(strict_types=1);

namespace App\Staff\Domain\Service\Pds;

use App\Staff\Domain\Model\PersonalDataSheet;

/** A Personal Data Sheet printed on the layout of CS Form No. 212. */
interface PdsPdfInterface
{
    /** The PDF's content. */
    public function render(PersonalDataSheet $sheet): string;

    /** The HTML the PDF is made from. */
    public function html(PersonalDataSheet $sheet): string;

    /** "PDS_DELA-CRUZ_JUAN.pdf"; "PDS.pdf" while the name is empty. */
    public function fileName(PersonalDataSheet $sheet): string;
}
