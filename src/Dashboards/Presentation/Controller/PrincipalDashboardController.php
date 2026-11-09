<?php

declare(strict_types=1);

namespace App\Dashboards\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Dashboards\Application\Query\Command\GetPrincipalDashboardCommand;
use App\Dashboards\Application\Query\Command\GetPrincipalNowCommand;
use App\Dashboards\Application\Query\Command\GetPrincipalSchoolCommand;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_PRINCIPAL)]
#[Route('/principal')]
class PrincipalDashboardController extends AbstractDashboardController
{
    #[Route('', name: 'principal_dashboard', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $dashboard = $this->ask(new GetPrincipalDashboardCommand((int) $user->getId()));
        if (null === $dashboard['school']) {
            return $this->notAssigned($dashboard['school_year']);
        }

        return $this->render('@Dashboards/principal/dashboard.html.twig', $dashboard);
    }

    /** The "Classes right now" card: only the turbo-frame, so the dashboard can reload it in place. */
    #[Route('/now', name: 'principal_now', methods: ['GET'])]
    public function now(#[CurrentUser] User $user): Response
    {
        return $this->render(
            '@Dashboards/principal/_now.html.twig',
            $this->ask(new GetPrincipalNowCommand((int) $user->getId())),
        );
    }

    #[Route('/teachers', name: 'principal_teachers', methods: ['GET'])]
    public function teachers(#[CurrentUser] User $user): Response
    {
        return $this->toSchoolPage($user, 'school_teachers');
    }

    #[Route('/sections', name: 'principal_sections', methods: ['GET'])]
    public function sections(#[CurrentUser] User $user): Response
    {
        return $this->toSchoolPage($user, 'school_sections');
    }

    #[Route('/learners', name: 'principal_learners', methods: ['GET'])]
    public function learners(#[CurrentUser] User $user): Response
    {
        return $this->toSchoolPage($user, 'school_learners');
    }

    #[Route('/attendance', name: 'principal_attendance', methods: ['GET'])]
    public function attendance(#[CurrentUser] User $user): Response
    {
        return $this->toSchoolPage($user, 'school_attendance');
    }

    #[Route('/nutrition', name: 'principal_nutrition', methods: ['GET'])]
    public function nutrition(#[CurrentUser] User $user): Response
    {
        return $this->toSchoolPage($user, 'school_nutrition');
    }

    #[Route('/grades', name: 'principal_grades', methods: ['GET'])]
    public function grades(#[CurrentUser] User $user): Response
    {
        return $this->toSchoolPage($user, 'school_grades');
    }

    #[Route('/subjects', name: 'principal_subjects', methods: ['GET'])]
    public function subjects(#[CurrentUser] User $user): Response
    {
        return $this->toSchoolPage($user, 'school_subjects');
    }

    #[Route('/class-periods', name: 'principal_class_periods', methods: ['GET'])]
    public function classPeriods(#[CurrentUser] User $user): Response
    {
        return $this->toSchoolPage($user, 'school_class_periods');
    }

    #[Route('/service-record', name: 'principal_service_record', methods: ['GET'])]
    public function serviceRecord(#[CurrentUser] User $user): Response
    {
        $schoolId = $this->ask(new GetPrincipalSchoolCommand((int) $user->getId()))['schoolId']
            ?? throw $this->createAccessDeniedException('You are not assigned to a school this school year.');

        return $this->redirectToRoute('service_record_show', ['id' => $schoolId, 'employee' => $user->getId()]);
    }

    /** Redirects to the school page $route of the principal's school, or shows that they have none. */
    private function toSchoolPage(User $user, string $route): Response
    {
        $school = $this->ask(new GetPrincipalSchoolCommand((int) $user->getId()));
        if (null === $school['schoolId']) {
            return $this->notAssigned($school['school_year']);
        }

        return $this->redirectToRoute($route, ['id' => $school['schoolId']]);
    }

    private function notAssigned(mixed $schoolYear): Response
    {
        return $this->render('@Core/principal/not_assigned.html.twig', [
            'school_year' => $schoolYear,
            'not_assigned' => true,
        ]);
    }
}
