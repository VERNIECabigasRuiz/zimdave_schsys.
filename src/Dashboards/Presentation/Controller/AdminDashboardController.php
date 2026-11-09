<?php

declare(strict_types=1);

namespace App\Dashboards\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Query\Command\GetRecentActivityCommand;
use App\Dashboards\Application\Query\Command\GetAdminDashboardCommand;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
class AdminDashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin_dashboard')]
    public function index(ClockInterface $clock): Response
    {
        $dashboard = $this->ask(new GetAdminDashboardCommand($clock->now()->modify('+30 days')));

        return $this->render('@Dashboards/admin/dashboard.html.twig', $dashboard + [
            'activity' => $this->ask(new GetRecentActivityCommand(15)),
        ]);
    }
}
