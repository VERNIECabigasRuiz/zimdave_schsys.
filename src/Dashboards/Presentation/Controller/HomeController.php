<?php

declare(strict_types=1);

namespace App\Dashboards\Presentation\Controller;

use App\Access\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    /**
     * Sends each user to the home page for their role.
     */
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        if ($this->isGranted(User::ROLE_ADMIN)) {
            return $this->redirectToRoute('admin_dashboard');
        }
        if ($this->isGranted(User::ROLE_PRINCIPAL)) {
            return $this->redirectToRoute('principal_dashboard');
        }

        if ($this->isGranted(User::ROLE_TEACHER)) {
            return $this->redirectToRoute('teacher_dashboard');
        }

        if ($this->isGranted(User::ROLE_STUDENT)) {
            return $this->redirectToRoute('student_dashboard');
        }

        return $this->render('@Dashboards/home/index.html.twig');
    }
}
