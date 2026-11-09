<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Domain\Exception\ImageProcessingException;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Application\Query\Command\GetDivisionCommand;
use App\Schools\Application\Query\Command\ListDivisionsCommand;
use App\Schools\Application\UseCase\Command\CreateDivisionCommand;
use App\Schools\Application\UseCase\Command\DivisionDetails;
use App\Schools\Application\UseCase\Command\UpdateDivisionCommand;
use App\Schools\Presentation\Form\DivisionFormType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Schools divisions and their Superintendents, who sign principals' IDs.
 *
 * @phpstan-import-type DivisionData from \App\Schools\Application\Transformer\DivisionTransformer
 */
#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin/divisions')]
class DivisionController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('', name: 'admin_divisions', methods: ['GET'])]
    public function index(): Response
    {
        $result = $this->queryBus->handle(new ListDivisionsCommand());
        $this->domainFailure($result);

        return $this->render('@Schools/admin/divisions/index.html.twig', ['divisions' => $result->data()]);
    }

    #[Route('/new', name: 'admin_divisions_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->form($request, new CreateDivisionCommand(), null, 'Added division %s.');
    }

    #[Route('/{id}/edit', name: 'admin_divisions_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $result = $this->queryBus->handle(new GetDivisionCommand($id));
        if (null !== $this->domainFailure($result)) {
            throw $this->createNotFoundException();
        }
        /** @var DivisionData $division */
        $division = $result->data();

        return $this->form($request, UpdateDivisionCommand::fromDivision($division), $division, 'Saved division %s.');
    }

    /** @param DivisionData|null $division */
    private function form(Request $request, DivisionDetails $command, ?array $division, string $saved): Response
    {
        $form = $this->createForm(DivisionFormType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $signature = $form->get('signature')->getData();
            $command->signatureFile = $signature instanceof UploadedFile ? $signature->getPathname() : null;
            $result = $this->useCaseBus->handle($command);
            $failure = $this->domainFailure($result);
            if (null === $failure) {
                /** @var array{name: string} $data */
                $data = $result->data();
                $this->addFlash('success', \sprintf($saved, $data['name']));

                return $this->redirectToRoute('admin_divisions');
            }
            $field = $failure instanceof ImageProcessingException ? 'signature' : 'name';
            $form->get($field)->addError(new FormError($failure->getMessage()));
        }

        return $this->render('@Schools/admin/divisions/form.html.twig', ['form' => $form, 'division' => $division]);
    }
}
