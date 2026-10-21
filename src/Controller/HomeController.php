<?php

namespace App\Controller;

use App\Entity\User;
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

        // Student pages are built next
        return $this->render('home/index.html.twig');
    }
}
