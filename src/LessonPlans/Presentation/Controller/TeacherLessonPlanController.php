<?php

declare(strict_types=1);

namespace App\LessonPlans\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\TeachingLoad;
use App\LessonPlans\Application\Query\Command\GetLessonPlanCommand;
use App\LessonPlans\Application\Query\Command\GetLessonPlanIdCommand;
use App\LessonPlans\Application\Query\Command\GetLessonPlanStartCommand;
use App\LessonPlans\Application\Query\Command\GetTeacherLessonPlansCommand;
use App\LessonPlans\Application\Service\LessonPlanPolicy;
use App\LessonPlans\Application\UseCase\Command\DeleteLessonPlanCommand;
use App\LessonPlans\Application\UseCase\Command\SaveLessonPlanCommand;
use App\LessonPlans\Application\UseCase\Command\StartLessonPlanCommand;
use App\LessonPlans\Domain\Model\LessonPlan;
use App\LessonPlans\Presentation\Form\LessonPlanContent;
use App\LessonPlans\Presentation\Form\LessonPlanFormType;
use App\LessonPlans\Presentation\Form\LessonPlanStartFormType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A teacher's ILAW lesson plans: start one for a class and week, fill it in, delete it. */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher/lesson-plans')]
class TeacherLessonPlanController extends AbstractLessonPlanController
{
    #[Route('', name: 'teacher_lesson_plans', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $page = $this->ask(new GetTeacherLessonPlansCommand((int) $user->getId()));

        return $this->render('@LessonPlans/lesson_plans/index.html.twig', ['plans' => $page['plans']]);
    }

    #[Route('/new', name: 'teacher_lesson_plan_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        $page = $this->ask(new GetLessonPlanStartCommand((int) $user->getId()));
        $form = $this->createForm(
            LessonPlanStartFormType::class,
            ['week' => $page['today']],
            ['loads' => $page['loads']],
        );
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $load = $form->get('load')->getData();
            $week = $form->get('week')->getData();
            \assert($load instanceof TeachingLoad && $week instanceof \DateTimeImmutable);
            $this->refusal($this->useCaseBus->handle(
                new StartLessonPlanCommand((int) $user->getId(), (int) $load->getId(), $week),
            ));
            $plan = $this->ask(new GetLessonPlanIdCommand((int) $user->getId(), (int) $load->getId(), $week));

            return $this->redirectToRoute('teacher_lesson_plan_edit', ['id' => $plan['id']]);
        }

        return $this->render('@LessonPlans/lesson_plans/start.html.twig', [
            'form' => $form,
            'has_loads' => [] !== $page['loads'],
        ]);
    }

    #[Route('/{id}', name: 'teacher_lesson_plan_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        $plan = $this->ask(new GetLessonPlanCommand((int) $user->getId(), $id, LessonPlanPolicy::EDIT))['plan'];
        \assert($plan instanceof LessonPlan);
        $form = $this->createForm(LessonPlanFormType::class, LessonPlanContent::fromPlan($plan));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $content = $form->getData();
            $this->refusal($this->useCaseBus->handle(new SaveLessonPlanCommand(
                (int) $user->getId(),
                $id,
                $content->topic,
                $content->competencies,
                $content->classNotes,
                $content->references,
                $content->aiDeclaration,
                array_values($content->days),
            )));
            $this->addFlash('success', 'Lesson plan saved.');

            return $this->redirectToRoute('teacher_lesson_plan_edit', ['id' => $id]);
        }

        return $this->render('@LessonPlans/lesson_plans/edit.html.twig', [
            'plan' => $plan,
            // a refused save heads the page with the topic just typed
            'topic' => $form->isSubmitted() ? trim((string) $form->getData()->topic) : $plan->getTopic(),
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'teacher_lesson_plan_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        // who may delete is checked before the token, so another teacher gets 403
        $this->ask(new GetLessonPlanCommand((int) $user->getId(), $id, LessonPlanPolicy::EDIT));
        if (!$this->isCsrfTokenValid('lesson-plan-delete-' . $id, $request->getPayload()->getString('_token'))) {
            throw new InvalidCsrfTokenException('Invalid CSRF token.');
        }
        $this->refusal($this->useCaseBus->handle(new DeleteLessonPlanCommand((int) $user->getId(), $id)));
        $this->addFlash('success', 'Lesson plan deleted.');

        return $this->redirectToRoute('teacher_lesson_plans');
    }
}
