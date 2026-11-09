<?php

declare(strict_types=1);

namespace App\LessonPlans\Infrastructure\Pdf;

use App\Core\Domain\Service\Pdf\PdfRendererInterface;
use App\LessonPlans\Domain\Service\Pdf\LessonPlanPdfInterface;
use App\LessonPlans\Domain\Service\Pdf\LessonPlanPrintout;
use Twig\Environment;

/** An ILAW lesson plan as a PDF on letter landscape, laid out like DepEd's Daily Lesson Log – ILAW format. */
class LessonPlanPdf implements LessonPlanPdfInterface
{
    /** 11 × 8.5 in, in points */
    private const LETTER_LANDSCAPE = [0, 0, 792, 612];

    public function __construct(
        private readonly PdfRendererInterface $pdf,
        private readonly Environment $twig,
    ) {
    }

    public function render(LessonPlanPrintout $printout): string
    {
        return $this->pdf->render($this->html($printout), self::LETTER_LANDSCAPE);
    }

    public function html(LessonPlanPrintout $printout): string
    {
        return $this->twig->render('@LessonPlans/lesson_plans/pdf.html.twig', [
            'plan' => $printout->plan,
            'school' => $printout->school,
            'competencies' => $printout->competencies,
            'references' => $printout->references,
            'teacher' => $printout->teacher,
            'principal' => $printout->principal,
        ]);
    }
}
