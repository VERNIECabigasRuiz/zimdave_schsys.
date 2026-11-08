<?php

declare(strict_types=1);

namespace App\Nutrition\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Nutrition\Application\Query\Command\GetNutritionOverviewCommand;
use App\Nutrition\Application\UseCase\Command\ReopenSf8Command;
use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The school's SF8 — admin for any school, principal for their own: an overview of every section's
 * weighing for a period (or both side by side), and each section's sheet, read-only, with its SF8.
 * Only advisers weigh; the school side reopens a submitted period.
 */
#[Route('/schools/{id}', requirements: ['id' => '\d+', 'section' => '\d+', 'period' => 'baseline|endline'])]
#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]
class SchoolNutritionController extends AbstractNutritionController
{
    #[Route('/nutrition', name: 'school_nutrition', methods: ['GET'])]
    public function overview(
        School $school,
        #[CurrentUser] User $user,
        #[MapQueryParameter(flags: \FILTER_NULL_ON_FAILURE)] ?NutritionPeriod $period = null,
        #[MapQueryParameter(flags: \FILTER_NULL_ON_FAILURE)] ?bool $compare = null,
    ): Response {
        $period ??= NutritionPeriod::Baseline;
        $compare = true === $compare;
        $page = $this->ask(
            new GetNutritionOverviewCommand((int) $user->getId(), (int) $school->getId(), $period, $compare),
        );

        return $this->render('@Nutrition/nutrition/overview.html.twig', [
            'layout' => $this->layout(),
            'school' => $page['school'],
            'school_year' => $page['schoolYear'],
            'period' => $period,
            'compare' => $compare,
            'reports' => $page['reports'],
            'endlines' => $page['endlines'],
            'total' => $page['total'],
            'endline_total' => $page['endlineTotal'],
        ]);
    }

    #[Route('/sections/{section}/nutrition/{period}', name: 'school_nutrition_section', methods: ['GET'])]
    public function section(School $school, int $section, NutritionPeriod $period, #[CurrentUser] User $user): Response
    {
        $page = $this->periodPage((int) $user->getId(), (int) $school->getId(), $section, $period);

        return $this->renderSection($page, $period, [
            'layout' => $this->layout(),
            'period_route' => 'school_nutrition_section',
            'sf8_route' => 'school_nutrition_sf8',
            'route_params' => ['id' => $school->getId(), 'section' => $section],
            'index_route' => null,
            'reopen_url' => true === $page['canReopen']
                ? $this->generateUrl(
                    'school_nutrition_reopen',
                    ['id' => $school->getId(), 'section' => $section, 'period' => $period->value],
                )
                : null,
        ]);
    }

    #[Route('/sections/{section}/nutrition/{period}/sf8', name: 'school_nutrition_sf8', methods: ['GET'])]
    public function sf8(School $school, int $section, NutritionPeriod $period, #[CurrentUser] User $user): Response
    {
        return $this->downloadSf8((int) $user->getId(), (int) $school->getId(), $section, $period);
    }

    #[Route('/sections/{section}/nutrition/{period}/reopen', name: 'school_nutrition_reopen', methods: ['POST'])]
    #[IsCsrfTokenValid(
        new Expression('"nutrition-reopen-" ~ args["section"] ~ "-" ~ args["period"].value'),
        tokenKey: '_token',
    )]
    public function reopen(School $school, int $section, NutritionPeriod $period, #[CurrentUser] User $user): Response
    {
        $reopened = $this->useCaseBus->handle(
            new ReopenSf8Command((int) $user->getId(), (int) $school->getId(), $section, $period),
        );
        if (null !== $refusal = $this->refusal($reopened)) {
            $this->addFlash('error', $refusal);
        } else {
            $label = $reopened->data();
            \assert(\is_string($label));
            $this->addFlash('success', \sprintf('%s for %s reopened.', $period->label(), $label));
        }

        return $this->redirectToRoute(
            'school_nutrition_section',
            ['id' => $school->getId(), 'section' => $section, 'period' => $period->value],
        );
    }

    private function layout(): string
    {
        return $this->isGranted(User::ROLE_ADMIN) ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig';
    }
}
