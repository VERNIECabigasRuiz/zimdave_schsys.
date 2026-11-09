<?php

declare(strict_types=1);

namespace App\Nutrition\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Nutrition\Application\Query\Command\GetNutritionIndexCommand;
use App\Nutrition\Application\UseCase\Command\SaveWeighingCommand;
use App\Nutrition\Application\UseCase\Command\SubmitSf8Command;
use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Nutrition\Domain\Service\Sf8\Sf8Report;
use App\Nutrition\Domain\Service\Sf8\Sf8Status;
use App\Nutrition\Presentation\Form\NutritionSheet;
use App\Nutrition\Presentation\Form\NutritionSheetFormType;
use App\Nutrition\Presentation\Form\Sf8SubmitFormType;
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
        $page = $this->ask(new GetNutritionIndexCommand((int) $user->getId()));

        return $this->render('@Nutrition/nutrition/index.html.twig', [
            'section' => $page['section'],
            'reports' => $page['reports'],
        ]);
    }

    #[Route('/{period}', name: 'teacher_class_nutrition_period', methods: ['GET', 'POST'])]
    public function period(Request $request, #[CurrentUser] User $user, NutritionPeriod $period): Response
    {
        $page = $this->periodPage((int) $user->getId(), null, null, $period);
        $canFill = true === $page['canFill'];
        $report = $page['report'];
        $section = $page['section'];
        \assert($report instanceof Sf8Report && $section instanceof Section);

        $form = null;
        // a locked period renders read-only; a POST still goes to the recorder, which refuses it
        if ($canFill && ($request->isMethod('POST') || Sf8Status::Submitted !== $report->status)) {
            $form = $this->createForm(NutritionSheetFormType::class, NutritionSheet::fromReport($report));
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $sheet = $form->getData();
                $saved = $this->useCaseBus->handle(new SaveWeighingCommand(
                    (int) $user->getId(),
                    (int) $section->getId(),
                    $period,
                    $sheet->weighedOn ?? throw new \LogicException('The form requires the date of weighing.'),
                    $sheet->inputs(),
                ));
                $refusal = $this->refusal($saved);
                if (null === $refusal) {
                    $after = $this->periodPage((int) $user->getId(), null, null, $period)['report'];
                    \assert($after instanceof Sf8Report);
                    $weighed = $after->summary->weighed();
                    $this->addFlash('success', \sprintf(
                        'Saved %s weights for %d %s.',
                        $period->label(),
                        $weighed,
                        1 === $weighed ? 'learner' : 'learners',
                    ));

                    return $this->redirectToRoute('teacher_class_nutrition_period', ['period' => $period->value]);
                }
                $form->addError(new FormError($refusal));
            }
        }

        $submitForm = null;
        if ($canFill && Sf8Status::Draft === $report->status) {
            $submitForm = $this->submitForm($period, self::unweighed($page));
        }

        return $this->renderSection($page, $period, $this->routes() + [
            'form' => $form,
            'editable' => null !== $form && Sf8Status::Submitted !== $report->status,
            'submit_form' => $submitForm?->createView(),
        ]);
    }

    #[Route('/{period}/submit', name: 'teacher_class_nutrition_submit', methods: ['POST'])]
    public function submit(Request $request, #[CurrentUser] User $user, NutritionPeriod $period): Response
    {
        $page = $this->periodPage((int) $user->getId(), null, null, $period);
        $section = $page['section'];
        \assert($section instanceof Section);
        $form = $this->submitForm($period, self::unweighed($page));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $submitted = $this->useCaseBus->handle(new SubmitSf8Command(
                (int) $user->getId(),
                (int) $section->getId(),
                $period,
                $form->has('confirm_unweighed') && true === $form->get('confirm_unweighed')->getData(),
            ));
            if (null !== $refusal = $this->refusal($submitted)) {
                $this->addFlash('error', $refusal);
            } else {
                $this->addFlash('success', \sprintf('%s submitted to the principal.', $period->label()));
            }
        }

        return $this->redirectToRoute('teacher_class_nutrition_period', ['period' => $period->value]);
    }

    #[Route('/{period}/sf8', name: 'teacher_class_sf8', methods: ['GET'])]
    public function sf8(#[CurrentUser] User $user, NutritionPeriod $period): Response
    {
        return $this->downloadSf8((int) $user->getId(), null, null, $period);
    }

    /** @param array<string, mixed> $page the period query's data */
    private static function unweighed(array $page): int
    {
        \assert(\is_int($page['unweighed']));

        return $page['unweighed'];
    }

    /** @return FormInterface<mixed> */
    private function submitForm(NutritionPeriod $period, int $unweighed): FormInterface
    {
        return $this->createForm(Sf8SubmitFormType::class, null, [
            'unweighed' => $unweighed,
            'action' => $this->generateUrl('teacher_class_nutrition_submit', ['period' => $period->value]),
        ]);
    }

    /**
     * @return array{layout: string, period_route: string, sf8_route: string, route_params: array<string, mixed>,
     *     index_route: ?string}
     */
    private function routes(): array
    {
        return [
            'layout' => '@Core/teacher/_layout.html.twig',
            'period_route' => 'teacher_class_nutrition_period',
            'sf8_route' => 'teacher_class_sf8',
            'route_params' => [],
            'index_route' => 'teacher_class_nutrition',
        ];
    }
}
