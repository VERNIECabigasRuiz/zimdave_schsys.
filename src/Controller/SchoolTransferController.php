<?php

namespace App\Controller;

use App\Entity\School;
use App\Entity\User;
use App\Form\EnrollSectionFormType;
use App\Repository\SchoolYearRepository;
use App\Repository\SectionRepository;
use App\Security\Voter\LearnerVoter;
use App\Service\EnrollmentException;
use App\Service\LearnerLookup;
use App\Service\LookupKind;
use App\Service\PlanLimitException;
use App\Service\TransferManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Transfer requests between schools. */
#[Route('/schools/{id}/transfers', requirements: ['id' => '\d+'])]
class SchoolTransferController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SectionRepository $sections,
        private readonly LearnerLookup $lookup,
        private readonly TransferManager $transfers,
    ) {
    }

    /** A principal asks the learner's current school to let them move into one of their sections (from the enroll page). */
    #[Route('/request', name: 'school_transfers_request', methods: ['POST'])]
    #[IsGranted(LearnerVoter::REQUEST_TRANSFER, 'school')]
    public function request(Request $request, School $school, #[CurrentUser] User $user, #[MapQueryParameter] string $lrn = ''): Response
    {
        $schoolYear = $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');
        $result = $this->lookup->find($lrn, $school, $schoolYear);
        $backToEnroll = $this->redirectToRoute('school_learners_enroll', ['id' => $school->getId(), 'lrn' => $lrn]);
        if (LookupKind::Elsewhere !== $result->kind) {
            // enrolled here or unenrolled meanwhile: the enroll page shows what to do now
            return $backToEnroll;
        }

        // the form's CSRF token and its section choices (this school, this year) are checked here
        $form = $this->createForm(EnrollSectionFormType::class, null, ['sections' => $this->sections->findForSchool($school, $schoolYear)]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $errors = $form->getErrors(true);
            $this->addFlash('error', \count($errors) > 0 ? $errors[0]->getMessage() : 'Choose a section.');

            return $backToEnroll;
        }

        try {
            $this->transfers->request($result->enrollment, $form->get('section')->getData(), $user);
            $this->addFlash('success', \sprintf('Transfer request sent to %s.', $result->enrollment->getSchool()->getName()));
        } catch (PlanLimitException|EnrollmentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $backToEnroll;
        }

        return $this->redirectToRoute('school_learners', ['id' => $school->getId()]);
    }
}
