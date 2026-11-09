<?php

declare(strict_types=1);

namespace App\LessonPlans\Domain\Service\Pdf;

/** A lesson plan as a PDF laid out like DepEd's Daily Lesson Log – ILAW format. */
interface LessonPlanPdfInterface
{
    /** The PDF's content. */
    public function render(LessonPlanPrintout $printout): string;

    /** The HTML the PDF is made from. */
    public function html(LessonPlanPrintout $printout): string;
}
