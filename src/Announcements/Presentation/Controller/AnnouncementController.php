<?php

declare(strict_types=1);

namespace App\Announcements\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Announcements\Application\Query\Command\GetAnnouncementCommand;
use App\Announcements\Application\Query\Command\ListAnnouncementsCommand;
use App\Announcements\Application\UseCase\Command\DeleteAnnouncementCommand;
use App\Announcements\Application\UseCase\Command\PostAnnouncementCommand;
use App\Announcements\Application\UseCase\Command\UpdateAnnouncementCommand;
use App\Announcements\Presentation\Form\AnnouncementFormType;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Domain\Exception\DomainException;
use App\Core\Presentation\Controller\BusController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @phpstan-import-type AnnouncementData from \App\Announcements\Application\Transformer\AnnouncementTransformer
 */
#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin/announcements')]
class AnnouncementController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('', name: 'admin_announcements', methods: ['GET'])]
    public function index(): Response
    {
        $result = $this->queryBus->handle(new ListAnnouncementsCommand());
        $this->domainFailure($result);

        return $this->render('@Announcements/admin/announcements/index.html.twig', [
            'announcements' => $result->data(),
        ]);
    }

    #[Route('/new', name: 'admin_announcement_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $author */
        $author = $this->getUser();
        $command = new PostAnnouncementCommand((int) $author->getId());
        $form = $this->createForm(AnnouncementFormType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->domainFailure($this->useCaseBus->handle($command));
            $this->addFlash('success', 'Announcement posted.');

            return $this->redirectToRoute('admin_announcements');
        }

        return $this->render('@Announcements/admin/announcements/form.html.twig', [
            'form' => $form,
            'announcement' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_announcement_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $announcement = $this->announcement($id);
        $command = UpdateAnnouncementCommand::fromAnnouncement($announcement);
        $form = $this->createForm(AnnouncementFormType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->notFoundOnFailure($this->domainFailure($this->useCaseBus->handle($command)));
            $this->addFlash('success', 'Announcement saved.');

            return $this->redirectToRoute('admin_announcements');
        }

        return $this->render('@Announcements/admin/announcements/form.html.twig', [
            'form' => $form,
            'announcement' => $announcement,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_announcement_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"announcement-" ~ args["id"]'), tokenKey: '_token')]
    public function delete(int $id): Response
    {
        $this->notFoundOnFailure($this->domainFailure($this->useCaseBus->handle(new DeleteAnnouncementCommand($id))));
        $this->addFlash('success', 'Announcement deleted.');

        return $this->redirectToRoute('admin_announcements');
    }

    /** @return AnnouncementData */
    private function announcement(int $id): array
    {
        $result = $this->queryBus->handle(new GetAnnouncementCommand($id));
        $this->notFoundOnFailure($this->domainFailure($result));
        /** @var AnnouncementData $announcement */
        $announcement = $result->data();

        return $announcement;
    }

    /** The only refusal here is a missing announcement, shown as a 404. */
    private function notFoundOnFailure(?DomainException $failure): void
    {
        if (null !== $failure) {
            throw $this->createNotFoundException();
        }
    }
}
