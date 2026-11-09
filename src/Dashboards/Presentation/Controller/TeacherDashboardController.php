<?php

declare(strict_types=1);

namespace App\Dashboards\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Dashboards\Application\Query\Command\GetTeacherDashboardCommand;
use App\Dashboards\Application\Query\Command\GetTeacherNowCommand;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The teacher's home. */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher')]
class TeacherDashboardController extends AbstractDashboardController
{
    #[Route('', name: 'teacher_dashboard', methods: ['GET'])]
    public function dashboard(#[CurrentUser] User $user): Response
    {
        return $this->render(
            '@Dashboards/teacher/dashboard.html.twig',
            $this->ask(new GetTeacherDashboardCommand((int) $user->getId())),
        );
    }

    /** The "Your class now" card: only the turbo-frame, so the dashboard can reload it in place. */
    #[Route('/now', name: 'teacher_now', methods: ['GET'])]
    public function now(#[CurrentUser] User $user): Response
    {
        return $this->render(
            '@Dashboards/teacher/_now.html.twig',
            $this->ask(new GetTeacherNowCommand((int) $user->getId())),
        );
    }
}
