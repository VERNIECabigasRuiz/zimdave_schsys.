<?php

declare(strict_types=1);

namespace App\Grading\Domain\Service\CardSlip;

/** Card slips as a print-ready PDF, two slips to a page. */
interface CardSlipPdfInterface
{
    /** @param list<CardSlip> $slips */
    public function render(array $slips): string;

    /**
     * The HTML the PDF is made from.
     *
     * @param list<CardSlip> $slips
     */
    public function html(array $slips): string;
}
