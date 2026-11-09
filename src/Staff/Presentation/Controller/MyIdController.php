<?php

declare(strict_types=1);

namespace App\Staff\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Staff\Application\Query\Command\GetMyIdCommand;
use App\Staff\Application\Query\Command\GetStaffIdPdfCommand;
use App\Staff\Application\UseCase\Command\SaveStaffIdDetailsCommand;
use App\Staff\Domain\Exception\StaffIdNotPrintableException;
use App\Staff\Domain\Model\StaffIdDetails;
use App\Staff\Domain\Service\StaffId\StaffIdSize;
use App\Staff\Presentation\Form\StaffIdDetailsFormType;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\String\u;

/** A teacher or principal prints their own ID; only their own, and only while they have a school this S.Y. */
#[IsGranted('IS_AUTHENTICATED')]
#[Route('/my-id')]
class MyIdController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('', name: 'my_id', methods: ['GET', 'POST'])]
    public function show(Request $request, #[CurrentUser] User $user): Response
    {
        $actorId = (int) $user->getId();
        $page = $this->ask(new GetMyIdCommand($actorId));
        \assert($page['details'] instanceof StaffIdDetails);
        $details = SaveStaffIdDetailsCommand::fromDetails($page['details'], $actorId);
        $form = $this->createForm(StaffIdDetailsFormType::class, $details);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->refusal($this->useCaseBus->handle($details));
            $this->addFlash('success', 'ID details saved.');

            return $this->redirectToRoute('my_id');
        }
        if ($form->isSubmitted()) {
            // after a refused save the card shows what was typed
            $page = $this->ask(new GetMyIdCommand($actorId, $details));
        }

        return $this->render('@Staff/staff_id/my_id.html.twig', [
            'card' => $page['card'],
            'form' => $form,
            'missing' => $page['missing'],
            'can_print' => $page['canPrint'],
            'is_principal' => $page['isPrincipal'],
            'division' => $page['division'],
            'school' => $page['school'],
        ]);
    }

    #[Route('/{size}.pdf', name: 'my_id_pdf', requirements: ['size' => 'big|small'], methods: ['GET'])]
    public function pdf(string $size, #[CurrentUser] User $user): Response
    {
        $result = $this->queryBus->handle(new GetStaffIdPdfCommand((int) $user->getId(), StaffIdSize::from($size)));
        if ($result->exception() instanceof StaffIdNotPrintableException) {
            $this->addFlash('error', $result->exception()->getMessage());

            return $this->redirectToRoute('my_id');
        }
        $this->refusal($result);
        $file = $result->data();
        \assert(\is_array($file) && \is_string($file['content']) && \is_string($file['fileName']));

        return new Response($file['content'], Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $file['fileName'],
                (string) u($file['fileName'])->ascii(),
            ),
        ]);
    }

    /**
     * The data of a page query; someone without an ID here gets a 403.
     *
     * @return array<string, mixed>
     */
    private function ask(Query $query): array
    {
        $result = $this->queryBus->handle($query);
        $this->refusal($result);
        $data = $result->data();
        \assert(\is_array($data));

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** Ends a refused page or change: only teachers and principals with a school have an ID here. */
    private function refusal(Result $result): void
    {
        if (null !== $failure = $this->domainFailure($result)) {
            throw $this->createAccessDeniedException($failure->getMessage());
        }
    }
}
