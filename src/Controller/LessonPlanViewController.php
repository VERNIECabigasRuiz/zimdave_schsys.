<?php

namespace App\Controller;

use App\Entity\LessonPlan;
use App\Access\Domain\Model\User;
use App\LessonPlan\LessonPlanPdf;
use App\Repository\LessonPlanRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use App\Security\Voter\LessonPlanVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Reading and printing lesson plans: the teacher's own, and every plan of the principal's school. */
class LessonPlanViewController extends AbstractController
{
    #[Route('/principal/lesson-plans', name: 'principal_lesson_plans', methods: ['GET'])]
    #[IsGranted(User::ROLE_PRINCIPAL)]
    public function school(#[CurrentUser] User $user, SchoolYearRepository $schoolYears, SchoolAssignmentRepository $assignments, LessonPlanRepository $plans): Response
    {
        $schoolYear = $schoolYears->findCurrent();
        $school = null === $schoolYear ? null : $assignments->findSchoolOf($user, $schoolYear, User::ROLE_PRINCIPAL);
        if (null === $school) {
            return $this->render('principal/not_assigned.html.twig', ['school_year' => $schoolYear, 'not_assigned' => true]);
        }

        return $this->render('lesson_plans/school.html.twig', ['school' => $school, 'plans' => $plans->findForSchool($school, $schoolYear)]);
    }

    #[Route('/lesson-plans/{id}', name: 'lesson_plan_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(LessonPlanVoter::VIEW, 'plan')]
    public function show(LessonPlan $plan): Response
    {
        return $this->render('lesson_plans/show.html.twig', ['plan' => $plan]);
    }

    #[Route('/lesson-plans/{id}.pdf', name: 'lesson_plan_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(LessonPlanVoter::VIEW, 'plan')]
    public function pdf(LessonPlan $plan, LessonPlanPdf $pdf): Response
    {
        return new Response($pdf->render($plan), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, LessonPlanPdf::filename($plan), LessonPlanPdf::asciiFilename($plan)),
        ]);
    }
}
