<?php

namespace App\Controller;

use App\Entity\Section;
use App\Entity\User;
use App\Form\NutritionSheet;
use App\Form\NutritionSheetFormType;
use App\Form\Sf8SubmitFormType;
use App\Nutrition\NutritionException;
use App\Nutrition\NutritionPeriod;
use App\Nutrition\Sf8Report;
use App\Nutrition\Sf8Row;
use App\Nutrition\Sf8Status;
use App\Security\Voter\NutritionVoter;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The adviser's SF8: the baseline and endline weighing sheets, their submission and the download.
 * The advisory is looked up on every request, as on the class pages.
 */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher/class/nutrition', requirements: ['period' => 'baseline|endline'])]
class TeacherNutritionController extends AbstractNutritionController
{
    #[Route('', name: 'teacher_class_nutrition', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $section = $this->advisory($user);

        return $this->render('nutrition/index.html.twig', [
            'section' => $section,
            'reports' => array_map(fn (NutritionPeriod $period) => $this->reports->build($section, $period), NutritionPeriod::cases()),
        ]);
    }

    #[Route('/{period}', name: 'teacher_class_nutrition_period', methods: ['GET', 'POST'])]
    public function period(Request $request, #[CurrentUser] User $user, NutritionPeriod $period): Response
    {
        $section = $this->advisory($user);
        $canFill = $this->isGranted(NutritionVoter::FILL, $section);
        if ($request->isMethod('POST') && !$canFill) {
            throw $this->createAccessDeniedException('You can no longer weigh this section.');
        }

        $report = $this->reports->build($section, $period);
        $form = null;
        // a locked period renders read-only; a POST still goes to the recorder, which refuses it
        if ($canFill && ($request->isMethod('POST') || Sf8Status::Submitted !== $report->status)) {
            $form = $this->createForm(NutritionSheetFormType::class, NutritionSheet::fromReport($report));
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var NutritionSheet $sheet */
                $sheet = $form->getData();
                try {
                    $this->recorder->save($section, $period, $sheet->weighedOn, $sheet->inputs(), $user);
                    $weighed = $this->reports->build($section, $period)->summary->weighed();
                    $this->addFlash('success', \sprintf('Saved %s weights for %d %s.', $period->label(), $weighed, 1 === $weighed ? 'learner' : 'learners'));

                    return $this->redirectToRoute('teacher_class_nutrition_period', ['period' => $period->value]);
                } catch (NutritionException $e) {
                    $form->addError(new FormError($e->getMessage()));
                }
            }
        }

        $submitForm = null;
        if ($canFill && Sf8Status::Draft === $report->status) {
            $submitForm = $this->submitForm($report);
        }

        return $this->renderSection($section, $period, $this->routes() + [
            'form' => $form,
            'editable' => null !== $form && Sf8Status::Submitted !== $report->status,
            'submit_form' => $submitForm?->createView(),
        ], $report);
    }

    #[Route('/{period}/submit', name: 'teacher_class_nutrition_submit', methods: ['POST'])]
    public function submit(Request $request, #[CurrentUser] User $user, NutritionPeriod $period): Response
    {
        $section = $this->advisory($user);
        $this->denyAccessUnlessGranted(NutritionVoter::FILL, $section);

        $form = $this->submitForm($this->reports->build($section, $period));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->recorder->submit($section, $period, $user, $form->has('confirm_unweighed') && true === $form->get('confirm_unweighed')->getData());
                $this->addFlash('success', \sprintf('%s submitted to the principal.', $period->label()));
            } catch (NutritionException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('teacher_class_nutrition_period', ['period' => $period->value]);
    }

    #[Route('/{period}/sf8', name: 'teacher_class_sf8', methods: ['GET'])]
    public function sf8(#[CurrentUser] User $user, NutritionPeriod $period): Response
    {
        return $this->downloadSf8($this->advisory($user), $period);
    }

    private function submitForm(Sf8Report $report): FormInterface
    {
        return $this->createForm(Sf8SubmitFormType::class, null, [
            'unweighed' => \count(array_filter($report->rows, static fn (Sf8Row $row) => !$row->weighed)),
            'action' => $this->generateUrl('teacher_class_nutrition_submit', ['period' => $report->period->value]),
        ]);
    }

    /** @return array{layout: string, period_route: string, sf8_route: string, route_params: array<string, mixed>, index_route: ?string} */
    private function routes(): array
    {
        return [
            'layout' => 'teacher/_layout.html.twig',
            'period_route' => 'teacher_class_nutrition_period',
            'sf8_route' => 'teacher_class_sf8',
            'route_params' => [],
            'index_route' => 'teacher_class_nutrition',
        ];
    }

    private function advisory(User $user): Section
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $section = null === $schoolYear ? null : $this->sections->findAdvisory($user, $schoolYear);
        if (null === $section) {
            throw $this->createAccessDeniedException('You do not advise a section this school year.');
        }
        $this->denyAccessUnlessGranted(NutritionVoter::VIEW, $section);

        return $section;
    }
}
