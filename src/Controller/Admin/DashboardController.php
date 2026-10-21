<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\ActivityLogRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard')]
    public function index(
        UserRepository $users,
        ActivityLogRepository $activity,
        SchoolYearRepository $schoolYears,
        SchoolRepository $schools,
        SchoolAssignmentRepository $assignments,
        ClockInterface $clock,
    ): Response {
        $currentYear = $schoolYears->findCurrent();

        return $this->render('admin/dashboard.html.twig', [
            'plans_expiring' => $schools->countExpiringBy($clock->now()->modify('+30 days')),
            'school_year' => $currentYear,
            'year_stats' => $currentYear ? [
                'schools' => $schools->count(['active' => true]),
                'assigned' => $assignments->countPrincipals($currentYear),
                'unassigned' => \count($users->findAssignablePrincipals($currentYear)),
                'without_principal' => $schools->countActiveWithoutPrincipal($currentYear),
            ] : null,
            'stats' => [
                'accounts' => $users->count([]),
                'principals' => $users->countByRole(User::ROLE_PRINCIPAL),
                'teachers' => $users->countByRole(User::ROLE_TEACHER),
                'students' => $users->countByRole(User::ROLE_STUDENT),
                'inactive' => $users->countInactive(),
            ],
            'activity' => $activity->findRecent(15),
        ]);
    }
}
