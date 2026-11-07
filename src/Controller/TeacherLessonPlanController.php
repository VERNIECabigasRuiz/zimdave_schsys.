<?php

namespace App\Controller;

use App\Entity\LessonPlan;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Access\Domain\Model\User;
use App\Form\LessonPlanFormType;
use App\Form\LessonPlanStartFormType;
use App\LessonPlan\LessonWeek;
use App\Repository\LessonPlanRepository;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Classes\Domain\Model\Repository\TeachingLoadRepositoryInterface;
use App\Security\Voter\LessonPlanVoter;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A teacher's ILAW lesson plans: start one for a class and week, fill it in, delete it. */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher/lesson-plans')]
class TeacherLessonPlanController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly TeachingLoadRepositoryInterface $loads,
        private readonly LessonPlanRepository $plans,
        private readonly LessonWeek $week,
        private readonly SchoolClockInterface $clock,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'teacher_lesson_plans', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        [$schoolYear] = $this->schoolOf($user);

        return $this->render('lesson_plans/index.html.twig', [
            'plans' => null === $schoolYear ? [] : $this->plans->findForTeacher($user, $schoolYear),
        ]);
    }

    #[Route('/new', name: 'teacher_lesson_plan_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        [$schoolYear, $school] = $this->schoolOf($user);
        $loads = null === $school ? [] : $this->loads->findForTeacher($user, $schoolYear);
        $form = $this->createForm(LessonPlanStartFormType::class, ['week' => $this->clock->now()->setTime(0, 0)], ['loads' => $loads]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $load = $form->get('load')->getData();
            $monday = LessonWeek::monday($form->get('week')->getData());
            $plan = $this->plans->findOneFor($load, $monday);
            if (null === $plan) {
                $plan = $this->week->start($load, $monday, $this->clock->now());
                $this->entityManager->persist($plan);
                $this->entityManager->flush();
            }

            return $this->redirectToRoute('teacher_lesson_plan_edit', ['id' => $plan->getId()]);
        }

        return $this->render('lesson_plans/start.html.twig', ['form' => $form, 'has_loads' => [] !== $loads]);
    }

    #[Route('/{id}', name: 'teacher_lesson_plan_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(LessonPlanVoter::EDIT, 'plan')]
    public function edit(Request $request, LessonPlan $plan): Response
    {
        $form = $this->createForm(LessonPlanFormType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plan->touch($this->clock->now());
            $this->entityManager->flush();
            $this->addFlash('success', 'Lesson plan saved.');

            return $this->redirectToRoute('teacher_lesson_plan_edit', ['id' => $plan->getId()]);
        }

        return $this->render('lesson_plans/edit.html.twig', [
            'plan' => $plan,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'teacher_lesson_plan_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(LessonPlanVoter::EDIT, 'plan')]
    #[IsCsrfTokenValid(new Expression('"lesson-plan-delete-" ~ args["plan"].getId()'), tokenKey: '_token')]
    public function delete(LessonPlan $plan): Response
    {
        $this->entityManager->remove($plan);
        $this->entityManager->flush();
        $this->addFlash('success', 'Lesson plan deleted.');

        return $this->redirectToRoute('teacher_lesson_plans');
    }

    /** @return array{0: ?SchoolYear, 1: ?School} */
    private function schoolOf(User $user): array
    {
        $schoolYear = $this->schoolYears->findCurrent();

        return [$schoolYear, null === $schoolYear ? null : $this->assignments->findSchoolOf($user, $schoolYear, User::ROLE_TEACHER)];
    }
}
