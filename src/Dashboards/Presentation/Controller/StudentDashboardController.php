<?php

declare(strict_types=1);

namespace App\Dashboards\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Dashboards\Application\Query\Command\GetStudentDashboardCommand;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The student's home: where they are enrolled and their section's timetable, read only. */
#[IsGranted(User::ROLE_STUDENT)]
#[Route('/student')]
class StudentDashboardController extends AbstractDashboardController
{
    #[Route('', name: 'student_dashboard', methods: ['GET'])]
    public function dashboard(Request $request, #[CurrentUser] User $user): Response
    {
        $dashboard = $this->ask(new GetStudentDashboardCommand((int) $user->getId(), null));
        // ?term= is read only when there is a timetable to show
        if (null !== $dashboard['section'] && $request->query->has('term')) {
            $term = $request->query->getInt('term');
            $dashboard = $this->ask(new GetStudentDashboardCommand((int) $user->getId(), $term));
        }

        return $this->render('@Dashboards/student/dashboard.html.twig', $dashboard);
    }
}
