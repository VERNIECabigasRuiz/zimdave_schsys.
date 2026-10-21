<?php

namespace App\Controller\Principal;

use App\Entity\School;
use App\Entity\User;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use App\Service\PlanLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_PRINCIPAL)]
#[Route('/principal')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly PlanLimits $planLimits,
    ) {
    }

    #[Route('', name: 'principal_dashboard', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $school = $this->ownSchool($user);
        if (null === $school) {
            return $this->notAssigned();
        }

        return $this->render('principal/dashboard.html.twig', [
            'school' => $school,
            'school_year' => $schoolYear,
            'teachers' => $this->planLimits->countTeachers($school, $schoolYear),
            'blocked_reason' => $this->planLimits->blockedReason($school),
        ]);
    }

    #[Route('/teachers', name: 'principal_teachers', methods: ['GET'])]
    public function teachers(#[CurrentUser] User $user): Response
    {
        $school = $this->ownSchool($user);
        if (null === $school) {
            return $this->notAssigned();
        }

        return $this->redirectToRoute('school_teachers', ['id' => $school->getId()]);
    }

    #[Route('/sections', name: 'principal_sections', methods: ['GET'])]
    public function sections(#[CurrentUser] User $user): Response
    {
        $school = $this->ownSchool($user);
        if (null === $school) {
            return $this->notAssigned();
        }

        return $this->redirectToRoute('school_sections', ['id' => $school->getId()]);
    }

    private function ownSchool(User $user): ?School
    {
        $schoolYear = $this->schoolYears->findCurrent();

        return $schoolYear ? $this->assignments->findSchoolOf($user, $schoolYear, User::ROLE_PRINCIPAL) : null;
    }

    private function notAssigned(): Response
    {
        return $this->render('principal/not_assigned.html.twig', [
            'school_year' => $this->schoolYears->findCurrent(),
        ]);
    }
}
