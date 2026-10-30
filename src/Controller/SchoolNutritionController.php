<?php

namespace App\Controller;

use App\Entity\School;
use App\Entity\Section;
use App\Entity\User;
use App\Nutrition\NutritionException;
use App\Nutrition\NutritionPeriod;
use App\Nutrition\Sf8Report;
use App\Nutrition\Sf8Summary;
use App\Security\Voter\NutritionVoter;
use App\Security\Voter\SchoolVoter;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The school's SF8 — admin for any school, principal for their own: an overview of every section's
 * weighing for a period (or both side by side), and each section's sheet, read-only, with its SF8.
 * Only advisers weigh; the school side reopens a submitted period.
 */
#[Route('/schools/{id}', requirements: ['id' => '\d+', 'section' => '\d+', 'period' => 'baseline|endline'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolNutritionController extends AbstractNutritionController
{
    #[Route('/nutrition', name: 'school_nutrition', methods: ['GET'])]
    public function overview(
        School $school,
        #[MapQueryParameter(flags: \FILTER_NULL_ON_FAILURE)] ?NutritionPeriod $period = null,
        #[MapQueryParameter(flags: \FILTER_NULL_ON_FAILURE)] ?bool $compare = null,
    ): Response {
        $schoolYear = $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');
        $period ??= NutritionPeriod::Baseline;
        $compare = true === $compare;

        $reports = $this->reports->buildForSchool($school, $schoolYear, $compare ? NutritionPeriod::Baseline : $period);
        $endlines = [];
        if ($compare) {
            foreach ($this->reports->buildForSchool($school, $schoolYear, NutritionPeriod::Endline) as $report) {
                $endlines[$report->section->getId()] = $report;
            }
        }

        return $this->render('nutrition/overview.html.twig', [
            'layout' => $this->layout(),
            'school' => $school,
            'school_year' => $schoolYear,
            'period' => $period,
            'compare' => $compare,
            'reports' => $reports,
            'endlines' => $endlines,
            'total' => Sf8Summary::sum(array_map(static fn (Sf8Report $r) => $r->summary, $reports)),
            'endline_total' => $compare ? Sf8Summary::sum(array_map(static fn (Sf8Report $r) => $r->summary, array_values($endlines))) : null,
        ]);
    }

    #[Route('/sections/{section}/nutrition/{period}', name: 'school_nutrition_section', methods: ['GET'])]
    public function section(School $school, int $section, NutritionPeriod $period): Response
    {
        $section = $this->requireSection($school, $section);

        return $this->renderSection($section, $period, [
            'layout' => $this->layout(),
            'period_route' => 'school_nutrition_section',
            'sf8_route' => 'school_nutrition_sf8',
            'route_params' => ['id' => $school->getId(), 'section' => $section->getId()],
            'index_route' => null,
            'reopen_url' => $this->isGranted(NutritionVoter::REOPEN, $section)
                ? $this->generateUrl('school_nutrition_reopen', ['id' => $school->getId(), 'section' => $section->getId(), 'period' => $period->value])
                : null,
        ]);
    }

    #[Route('/sections/{section}/nutrition/{period}/sf8', name: 'school_nutrition_sf8', methods: ['GET'])]
    public function sf8(School $school, int $section, NutritionPeriod $period): Response
    {
        return $this->downloadSf8($this->requireSection($school, $section), $period);
    }

    #[Route('/sections/{section}/nutrition/{period}/reopen', name: 'school_nutrition_reopen', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"nutrition-reopen-" ~ args["section"] ~ "-" ~ args["period"].value'), tokenKey: '_token')]
    public function reopen(School $school, int $section, NutritionPeriod $period): Response
    {
        $section = $this->requireSection($school, $section);
        $this->denyAccessUnlessGranted(NutritionVoter::REOPEN, $section);

        try {
            $this->recorder->reopen($section, $period);
            $this->addFlash('success', \sprintf('%s for %s reopened.', $period->label(), $section->getLabel()));
        } catch (NutritionException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('school_nutrition_section', ['id' => $school->getId(), 'section' => $section->getId(), 'period' => $period->value]);
    }

    private function requireSection(School $school, int $id): Section
    {
        $section = $this->sections->find($id);
        if (null === $section || $section->getSchool()->getId() !== $school->getId()) {
            throw $this->createNotFoundException();
        }

        return $section;
    }

    private function layout(): string
    {
        return $this->isGranted(User::ROLE_ADMIN) ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig';
    }
}
