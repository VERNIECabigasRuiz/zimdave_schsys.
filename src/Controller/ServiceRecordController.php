<?php

namespace App\Controller;

use App\Entity\PersonalDataSheet;
use App\Entity\School;
use App\Entity\User;
use App\Repository\PersonalDataSheetRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\ServiceRecordEntryRepository;
use App\Repository\ServiceRecordRepository;
use App\Security\Voter\ServiceRecordVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly PersonalDataSheetRepository $sheets,
        private readonly ServiceRecordRepository $records,
        private readonly ServiceRecordEntryRepository $entries,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'service_record_show', methods: ['GET'])]
    #[IsGranted(ServiceRecordVoter::EDIT, 'school')]
    public function show(School $school, int $employee): Response
    {
        $employee = $this->requireEmployee($school, $employee);
        $sheet = $this->sheets->findOneBy(['user' => $employee]);

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
