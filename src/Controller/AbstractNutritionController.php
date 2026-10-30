<?php

namespace App\Controller;

use App\Entity\Section;
use App\Nutrition\NutritionPeriod;
use App\Nutrition\NutritionRecorder;
use App\Nutrition\NutritionRules;
use App\Nutrition\Sf8Report;
use App\Nutrition\Sf8ReportBuilder;
use App\Nutrition\Sf8Writer;
use App\Repository\SchoolYearRepository;
use App\Repository\SectionRepository;
use App\Security\Voter\NutritionVoter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The nutrition pages shared by the adviser's class and the school pages: a section's weighing
 * table for a period with its SF8 summary, and the SF8 download. Subclasses decide the routes and who may write.
 */
abstract class AbstractNutritionController extends AbstractController
{
    public function __construct(
        protected readonly Sf8ReportBuilder $reports,
        protected readonly Sf8Writer $writer,
        protected readonly NutritionRules $rules,
        protected readonly NutritionRecorder $recorder,
        protected readonly SchoolYearRepository $schoolYears,
        protected readonly SectionRepository $sections,
    ) {
    }

    /**
     * The section's page for the period: nutrition/sheet.html.twig with the report. Pass `form` (a
     * NutritionSheetFormType form) with `editable` to render inputs, `submit_form`, `reopen_url`; routes need
     * `layout`, `period_route`, `sf8_route`, `route_params` and `index_route` (nullable).
     *
     * @param array<string, mixed> $vars
     */
    protected function renderSection(Section $section, NutritionPeriod $period, array $vars, ?Sf8Report $report = null): Response
    {
        $this->denyAccessUnlessGranted(NutritionVoter::VIEW, $section);

        return $this->render('nutrition/sheet.html.twig', $vars + [
            'section' => $section,
            'period' => $period,
            'report' => $report ?? $this->reports->build($section, $period),
            'form' => null,
            'editable' => false,
            'submit_form' => null,
            'reopen_url' => null,
            'index_route' => null,
        ]);
    }

    protected function downloadSf8(Section $section, NutritionPeriod $period): Response
    {
        $this->denyAccessUnlessGranted(NutritionVoter::VIEW, $section);
        $report = $this->reports->build($section, $period);

        $file = tempnam(sys_get_temp_dir(), 'sf8');
        try {
            IOFactory::createWriter($this->writer->write($report), 'Xlsx')->save($file);
            $content = file_get_contents($file);
        } finally {
            unlink($file);
        }

        $fileName = $this->writer->filename($report);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            // section names may have an Ñ; old browsers get underscores
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, preg_replace('/[^\x20-\x7E]/', '_', $fileName)),
        ]);
    }
}
