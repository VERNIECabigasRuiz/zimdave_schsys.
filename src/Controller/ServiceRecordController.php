<?php

namespace App\Controller;

use App\Staff\Domain\Model\Repository\PersonalDataSheetRepositoryInterface;
use App\Staff\Domain\Model\PersonalDataSheet;
use App\Schools\Domain\Model\School;
use App\Entity\ServiceRecordEntry;
use App\Access\Domain\Model\User;
use App\Form\ServiceRecordEntryFormType;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Repository\ServiceRecordEntryRepository;
use App\Repository\ServiceRecordRepository;
use App\Security\Voter\ServiceRecordVoter;
use App\ServiceRecord\ServiceRecordKeeper;
use App\ServiceRecord\ServiceRecordPdf;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The service records of a school's people, kept by its principal (their own included): the header from the PDS,
 * the BP number, the rows, and the PDF. The record belongs to the person and follows them to their next school.
 */
#[Route('/schools/{id}/service-records/{employee}', requirements: ['id' => '\d+', 'employee' => '\d+', 'entry' => '\d+'])]
class ServiceRecordController extends AbstractController
{
    /** PDS fields the record prints, by label. */
    private const PDS_FIELDS = [
        'Surname' => 'surname',
        'First name' => 'firstName',
        'Date of birth' => 'dateOfBirth',
        'Place of birth' => 'placeOfBirth',
        'Agency employee no.' => 'agencyEmployeeNo',
    ];

    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly PersonalDataSheetRepositoryInterface $sheets,
        private readonly ServiceRecordRepository $records,
        private readonly ServiceRecordEntryRepository $entries,
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceRecordKeeper $keeper,
    ) {
    }

    #[Route('', name: 'service_record_show', methods: ['GET'])]
    #[IsGranted(ServiceRecordVoter::EDIT, 'school')]
    public function show(School $school, int $employee): Response
    {
        $employee = $this->requireEmployee($school, $employee);
        $sheet = $this->sheets->findFor($employee);

        return $this->render('service_record/show.html.twig', [
            'school' => $school,
            'employee' => $employee,
            'sheet' => $sheet,
            'missing' => self::missing($sheet),
            'record' => $this->records->findOrNew($employee),
            'rows' => $this->entries->findForEmployee($employee),
            'can_edit' => true,
        ]);
    }

    #[Route('/bp', name: 'service_record_bp', methods: ['POST'])]
    #[IsGranted(ServiceRecordVoter::EDIT, 'school')]
    #[IsCsrfTokenValid('service-record-bp', tokenKey: '_token')]
    public function bpNumber(Request $request, School $school, int $employee): Response
    {
        $employee = $this->requireEmployee($school, $employee);
        $record = $this->records->findOrNew($employee);
        $record->setBpNumber(mb_substr($request->getPayload()->getString('bp_number'), 0, 30));
        $this->entityManager->persist($record);
        $this->entityManager->flush();
        $this->addFlash('success', 'BP number saved.');

        return $this->redirectToRoute('service_record_show', ['id' => $school->getId(), 'employee' => $employee->getId()]);
    }

    /** The record as a PDF; administrators may print it too. Never writes. */
    #[Route('/service-record.pdf', name: 'service_record_pdf', methods: ['GET'])]
    #[IsGranted(ServiceRecordVoter::VIEW, 'school')]
    public function pdf(School $school, int $employee, ServiceRecordPdf $pdf): Response
    {
        $employee = $this->requireEmployee($school, $employee);

        return new Response($pdf->render($employee, $school), headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $pdf->filename($employee), $pdf->asciiFilename($employee)),
        ]);
    }

    #[Route('/rows/new', name: 'service_record_row_new', methods: ['GET', 'POST'])]
    #[IsGranted(ServiceRecordVoter::EDIT, 'school')]
    public function newRow(Request $request, School $school, int $employee, #[CurrentUser] User $user): Response
    {
        $employee = $this->requireEmployee($school, $employee);
        $entry = $this->keeper->draft($employee, $school, $user);
        $hasOpen = null !== $this->entries->findCurrent($employee);
        $entry->closeOpen = $hasOpen;

        return $this->rowForm($request, $school, $employee, $entry, $user, $hasOpen, 'Row added.');
    }

    #[Route('/rows/{entry}/edit', name: 'service_record_row_edit', methods: ['GET', 'POST'])]
    #[IsGranted(ServiceRecordVoter::EDIT, 'school')]
    public function editRow(Request $request, School $school, int $employee, int $entry, #[CurrentUser] User $user): Response
    {
        $employee = $this->requireEmployee($school, $employee);

        return $this->rowForm($request, $school, $employee, $this->requireEntry($employee, $entry), $user, false, 'Row saved.');
    }

    #[Route('/rows/{entry}/delete', name: 'service_record_row_delete', methods: ['POST'])]
    #[IsGranted(ServiceRecordVoter::EDIT, 'school')]
    #[IsCsrfTokenValid(new Expression('"service-row-" ~ args["entry"]'), tokenKey: '_token')]
    public function deleteRow(School $school, int $employee, int $entry): Response
    {
        $employee = $this->requireEmployee($school, $employee);
        $this->entityManager->remove($this->requireEntry($employee, $entry));
        $this->entityManager->flush();
        $this->addFlash('success', 'Row deleted.');

        return $this->redirectToRoute('service_record_show', ['id' => $school->getId(), 'employee' => $employee->getId()]);
    }

    private function rowForm(Request $request, School $school, User $employee, ServiceRecordEntry $entry, User $user, bool $canCloseOpen, string $saved): Response
    {
        $form = $this->createForm(ServiceRecordEntryFormType::class, $entry, ['can_close_open' => $canCloseOpen]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->keeper->save($entry, $user);
            $this->addFlash('success', $saved);

            return $this->redirectToRoute('service_record_show', ['id' => $school->getId(), 'employee' => $employee->getId()]);
        }

        return $this->render('service_record/row_form.html.twig', [
            'form' => $form,
            'school' => $school,
            'employee' => $employee,
            'entry' => $entry,
            'statuses' => ServiceRecordEntry::STATUSES,
        ]);
    }

    private function requireEntry(User $employee, int $id): ServiceRecordEntry
    {
        $entry = $this->entries->find($id);
        if (null === $entry || $entry->getEmployee()->getId() !== $employee->getId()) {
            throw $this->createNotFoundException('No such row in this service record.');
        }

        return $entry;
    }

    /** Someone assigned to the school this S.Y., in any role; 404 otherwise. */
    private function requireEmployee(School $school, int $employee): User
    {
        $currentYear = $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');
        $user = $this->entityManager->find(User::class, $employee);
        if (null === $user || $this->assignments->findForUser($user, $currentYear)?->getSchool()->getId() !== $school->getId()) {
            throw $this->createNotFoundException('This person does not belong to the school this school year.');
        }

        return $user;
    }

    /** @return list<string> labels of the PDS fields the record needs that are empty */
    private static function missing(?PersonalDataSheet $sheet): array
    {
        $missing = [];
        foreach (self::PDS_FIELDS as $label => $field) {
            $value = $sheet?->{$field};
            if (null === $value || '' === $value) {
                $missing[] = $label;
            }
        }

        return $missing;
    }
}
