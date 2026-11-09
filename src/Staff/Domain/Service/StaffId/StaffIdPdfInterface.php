<?php

declare(strict_types=1);

namespace App\Staff\Domain\Service\StaffId;

/** A teacher's or principal's ID as a PDF at actual size, on the school's Big or Small template. */
interface StaffIdPdfInterface
{
    /** The PDF's content. */
    public function render(StaffIdCard $card, StaffIdSize $size): string;

    /** The HTML the PDF is made from. */
    public function html(StaffIdCard $card, StaffIdSize $size): string;
}
