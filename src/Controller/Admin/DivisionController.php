<?php

namespace App\Controller\Admin;

use App\Entity\Division;
use App\Access\Domain\Model\User;
use App\Form\DivisionFormType;
use App\Repository\DivisionRepository;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Exception\ImageProcessingException;
use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Schools divisions and their Superintendents, who sign principals' IDs. */
#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin/divisions')]
class DivisionController extends AbstractController
{
    public function __construct(
        private readonly DivisionRepository $divisions,
        private readonly EntityManagerInterface $entityManager,
        private readonly ImageProcessorInterface $images,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    #[Route('', name: 'admin_divisions', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/divisions/index.html.twig', ['divisions' => $this->divisions->findAllOrdered()]);
    }

    #[Route('/new', name: 'admin_divisions_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->form($request, new Division(), 'Added division %s.');
    }

    #[Route('/{id}/edit', name: 'admin_divisions_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Division $division): Response
    {
        return $this->form($request, $division, 'Saved division %s.');
    }

    private function form(Request $request, Division $division, string $saved): Response
    {
        $form = $this->createForm(DivisionFormType::class, $division);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $signature = $form->get('signature')->getData();
            try {
                if ($signature instanceof UploadedFile) {
                    $division->setSignaturePath($this->images->storeSignature($signature->getPathname(), $division->getSignaturePath()));
                }
                $this->entityManager->persist($division);
                $message = \sprintf($saved, $division->getName());
                $this->activityLogger->log($message);
                $this->entityManager->flush();
                $this->addFlash('success', $message);

                return $this->redirectToRoute('admin_divisions');
            } catch (ImageProcessingException $e) {
                $form->get('signature')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('admin/divisions/form.html.twig', ['form' => $form, 'division' => $division->getId() ? $division : null]);
    }
}
