<?php

declare(strict_types=1);

namespace App\Staff\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Domain\Model\School;
use App\Staff\Application\Query\Command\GetServiceRecordCommand;
use App\Staff\Application\Query\Command\GetServiceRecordPdfCommand;
use App\Staff\Application\Query\Command\GetServiceRowFormCommand;
use App\Staff\Application\UseCase\Command\DeleteServiceRowCommand;
use App\Staff\Application\UseCase\Command\SaveBpNumberCommand;
use App\Staff\Application\UseCase\Command\SaveServiceRowCommand;
use App\Staff\Domain\Model\ServiceRecordEntry;
use App\Staff\Presentation\Form\ServiceRecordEntryFormType;
use App\Staff\Presentation\Security\ServiceRecordVoter;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\String\u;

/**
 * The service records of a school's people, kept by its principal (their own included): the header from the PDS,
 * the BP number, the rows, and the PDF. The record belongs to the person and follows them to their next school.
 */
#[Route(
    '/schools/{id}/service-records/{employee}',
    requirements: ['id' => '\d+', 'employee' => '\d+', 'entry' => '\d+'],
)]
class ServiceRecordController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('', name: 'service_record_show', methods: ['GET'])]
    #[IsGranted(ServiceRecordVoter::EDIT, 'school')]
    public function show(School $school, int $employee, #[CurrentUser] User $user): Response
    {
        $page = $this->ask(new GetServiceRecordCommand((int) $user->getId(), (int) $school->getId(), $employee));

        return $this->render('@Staff/service_record/show.html.twig', [
            'school' => $page['school'],
            'employee' => $page['employee'],
            'sheet' => $page['sheet'],
            'missing' => $page['missing'],
            'record' => $page['record'],
            'rows' => $page['rows'],
            'can_edit' => true,
        ]);
    }

    #[Route('/bp', name: 'service_record_bp', methods: ['POST'])]
    #[IsGranted(ServiceRecordVoter::EDIT, 'school')]
    #[IsCsrfTokenValid('service-record-bp', tokenKey: '_token')]
    public function bpNumber(Request $request, School $school, int $employee, #[CurrentUser] User $user): Response
    {
        $this->refusal($this->useCaseBus->handle(new SaveBpNumberCommand(
            (int) $user->getId(),
            (int) $school->getId(),
            $employee,
            $request->getPayload()->getString('bp_number'),
        )));
        $this->addFlash('success', 'BP number saved.');

        return $this->redirectToRoute('service_record_show', ['id' => $school->getId(), 'employee' => $employee]);
    }

    /** The record as a PDF; administrators may print it too. Never writes. */
    #[Route('/service-record.pdf', name: 'service_record_pdf', methods: ['GET'])]
    #[IsGranted(ServiceRecordVoter::VIEW, 'school')]
    public function pdf(School $school, int $employee, #[CurrentUser] User $user): Response
    {
        $file = $this->ask(new GetServiceRecordPdfCommand((int) $user->getId(), (int) $school->getId(), $employee));
        \assert(\is_string($file['content']) && \is_string($file['fileName']));

        return new Response($file['content'], headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $file['fileName'],
                (string) u($file['fileName'])->ascii(),
            ),
        ]);
    }

    #[Route('/rows/new', name: 'service_record_row_new', methods: ['GET', 'POST'])]
    #[IsGranted(ServiceRecordVoter::EDIT, 'school')]
    public function newRow(Request $request, School $school, int $employee, #[CurrentUser] User $user): Response
    {
        return $this->rowForm(
            $request,
            new GetServiceRowFormCommand((int) $user->getId(), (int) $school->getId(), $employee),
            'Row added.',
        );
    }

    #[Route('/rows/{entry}/edit', name: 'service_record_row_edit', methods: ['GET', 'POST'])]
    #[IsGranted(ServiceRecordVoter::EDIT, 'school')]
    public function editRow(
        Request $request,
        School $school,
        int $employee,
        int $entry,
        #[CurrentUser] User $user,
    ): Response {
        return $this->rowForm(
            $request,
            new GetServiceRowFormCommand((int) $user->getId(), (int) $school->getId(), $employee, $entry),
            'Row saved.',
        );
    }

    #[Route('/rows/{entry}/delete', name: 'service_record_row_delete', methods: ['POST'])]
    #[IsGranted(ServiceRecordVoter::EDIT, 'school')]
    #[IsCsrfTokenValid(new Expression('"service-row-" ~ args["entry"]'), tokenKey: '_token')]
    public function deleteRow(School $school, int $employee, int $entry, #[CurrentUser] User $user): Response
    {
        $this->refusal($this->useCaseBus->handle(
            new DeleteServiceRowCommand((int) $user->getId(), (int) $school->getId(), $employee, $entry),
        ));
        $this->addFlash('success', 'Row deleted.');

        return $this->redirectToRoute('service_record_show', ['id' => $school->getId(), 'employee' => $employee]);
    }

    private function rowForm(Request $request, GetServiceRowFormCommand $query, string $saved): Response
    {
        $page = $this->ask($query);
        $row = $page['row'];
        \assert($row instanceof SaveServiceRowCommand && \is_bool($page['canCloseOpen']));
        $form = $this->createForm(ServiceRecordEntryFormType::class, $row, ['can_close_open' => $page['canCloseOpen']]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->refusal($this->useCaseBus->handle($row));
            $this->addFlash('success', $saved);

            return $this->redirectToRoute('service_record_show', [
                'id' => $query->schoolId,
                'employee' => $query->employeeId,
            ]);
        }

        return $this->render('@Staff/service_record/row_form.html.twig', [
            'form' => $form,
            'school' => $page['school'],
            'employee' => $page['employee'],
            'entry_id' => $row->entryId,
            'statuses' => ServiceRecordEntry::STATUSES,
        ]);
    }

    /**
     * The data of a page query; anything not found is a 404.
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

    /** Ends a refused change or page: the person or row was not found, a 404. */
    private function refusal(Result $result): void
    {
        if (null !== $failure = $this->domainFailure($result)) {
            throw $this->createNotFoundException($failure->getMessage());
        }
    }
}
