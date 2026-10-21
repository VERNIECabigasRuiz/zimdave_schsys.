<?php

namespace App\Controller\Admin;

use App\Entity\Announcement;
use App\Entity\User;
use App\Form\AnnouncementFormType;
use App\Repository\AnnouncementRepository;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin/announcements')]
class AnnouncementController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityLogger $activityLogger,
    ) {
    }

    #[Route('', name: 'admin_announcements', methods: ['GET'])]
    public function index(AnnouncementRepository $announcements): Response
    {
        return $this->render('admin/announcements/index.html.twig', [
            'announcements' => $announcements->findNewestFirst(),
        ]);
    }

    #[Route('/new', name: 'admin_announcement_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $announcement = new Announcement();
        $form = $this->createForm(AnnouncementFormType::class, $announcement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $author */
            $author = $this->getUser();
            $announcement->setAuthor($author);

            $this->entityManager->persist($announcement);
            $this->activityLogger->log('Posted announcement: '.$announcement->getTitle());
            $this->entityManager->flush();

            $this->addFlash('success', 'Announcement posted.');

            return $this->redirectToRoute('admin_announcements');
        }

        return $this->render('admin/announcements/form.html.twig', [
            'form' => $form,
            'announcement' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_announcement_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Announcement $announcement): Response
    {
        $form = $this->createForm(AnnouncementFormType::class, $announcement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->activityLogger->log('Updated announcement: '.$announcement->getTitle());
            $this->entityManager->flush();

            $this->addFlash('success', 'Announcement saved.');

            return $this->redirectToRoute('admin_announcements');
        }

        return $this->render('admin/announcements/form.html.twig', [
            'form' => $form,
            'announcement' => $announcement,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_announcement_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"announcement-" ~ args["announcement"].getId()'), tokenKey: '_token')]
    public function delete(Announcement $announcement): Response
    {
        $this->activityLogger->log('Deleted announcement: '.$announcement->getTitle());
        $this->entityManager->remove($announcement);
        $this->entityManager->flush();

        $this->addFlash('success', 'Announcement deleted.');

        return $this->redirectToRoute('admin_announcements');
    }
}
