<?php

namespace App\Controller;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\TransferRequest;
use App\Entity\User;
use App\Form\EnrollSectionFormType;
use App\Repository\SchoolYearRepository;
use App\Repository\SectionRepository;
use App\Repository\TransferRequestRepository;
use App\Security\Voter\LearnerVoter;
use App\Security\Voter\SchoolVoter;
use App\Service\EnrollmentException;
use App\Service\LearnerLookup;
use App\Service\LookupKind;
use App\Service\PlanLimitException;
use App\Service\TransferManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Transfer requests between schools: the Incoming / Outgoing page, and requesting, approving,
 * declining and cancelling them. The page is for admins and the principal, and, on a plan
 * without a principal, for an adviser of the school (only what leaves their section).
 */
#[Route('/schools/{id}/transfers', requirements: ['id' => '\d+', 'transfer' => '\d+'])]
class SchoolTransferController extends AbstractController
{
    private const TABS = ['incoming', 'outgoing'];

    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SectionRepository $sections,
        private readonly TransferRequestRepository $requests,
        private readonly LearnerLookup $lookup,
        private readonly TransferManager $transfers,
    ) {
    }

    #[Route('', name: 'school_transfers', methods: ['GET'])]
    public function index(School $school, #[CurrentUser] User $user, #[MapQueryParameter] ?string $tab = null): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $adviserOf = $this->adviserScope($school, $user, $schoolYear);
        if (null !== $adviserOf) {
            if ('incoming' === $tab) {
                throw $this->createAccessDeniedException('Only the outgoing requests of your section are yours to see.');
            }
            $tab = 'outgoing';
        } elseif (!\in_array($tab, self::TABS, true)) {
            $tab = 'incoming';
        }

        $list = [];
        if (null !== $schoolYear) {
            $list = 'incoming' === $tab
                ? $this->requests->findIncoming($school, $schoolYear)
                : $this->requests->findOutgoing($school, $schoolYear, $adviserOf);
        }

        return $this->render('transfers/index.html.twig', [
            'school' => $school,
            'school_year' => $schoolYear,
            'layout' => $this->layout(),
            'tab' => $tab,
            'adviser_of' => $adviserOf,
            'requests' => $list,
        ]);
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

    #[Route('/{transfer}/approve', name: 'school_transfers_approve', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"transfer-" ~ args["transfer"]'), tokenKey: '_token')]
    public function approve(School $school, int $transfer, #[CurrentUser] User $user): Response
    {
        $request = $this->find($school, $transfer, outgoing: true);
        if (null !== $stale = $this->refuseDecided($school, $request, $user, 'outgoing')) {
            return $stale;
        }
        $this->denyAccessUnlessGranted(LearnerVoter::DECIDE_TRANSFER, $request);

        try {
            $this->transfers->approve($request, $user);
            $this->addFlash('success', \sprintf('Approved transfer of %s to %s.', $request->getLearner()->getUser()->getFullName(), $request->getToSchool()->getName()));
        } catch (PlanLimitException $e) {
            $this->addFlash('error', 'Approval refused: '.$e->getMessage());
        } catch (EnrollmentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->backTo($school, 'outgoing');
    }

    #[Route('/{transfer}/decline', name: 'school_transfers_decline', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"transfer-" ~ args["transfer"]'), tokenKey: '_token')]
    public function decline(School $school, int $transfer, #[CurrentUser] User $user): Response
    {
        $request = $this->find($school, $transfer, outgoing: true);
        if (null !== $stale = $this->refuseDecided($school, $request, $user, 'outgoing')) {
            return $stale;
        }
        $this->denyAccessUnlessGranted(LearnerVoter::DECIDE_TRANSFER, $request);

        try {
            $this->transfers->decline($request, $user);
            $this->addFlash('success', \sprintf('Declined transfer of %s.', $request->getLearner()->getUser()->getFullName()));
        } catch (EnrollmentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->backTo($school, 'outgoing');
    }

    #[Route('/{transfer}/cancel', name: 'school_transfers_cancel', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"transfer-" ~ args["transfer"]'), tokenKey: '_token')]
    public function cancel(School $school, int $transfer, #[CurrentUser] User $user): Response
    {
        $request = $this->find($school, $transfer, outgoing: false);
        if (null !== $stale = $this->refuseDecided($school, $request, $user, 'incoming')) {
            return $stale;
        }
        $this->denyAccessUnlessGranted(LearnerVoter::CANCEL_TRANSFER, $request);

        try {
            $this->transfers->cancel($request, $user);
            $this->addFlash('success', \sprintf('Cancelled transfer request for %s.', $request->getLearner()->getUser()->getFullName()));
        } catch (EnrollmentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->backTo($school, 'incoming');
    }

    /**
     * Who may open the page: admin and principal see the whole school (null); on a plan without a
     * principal the adviser of one of its sections sees that section. Anyone else is refused.
     */
    private function adviserScope(School $school, User $user, ?SchoolYear $schoolYear): ?Section
    {
        if ($this->isGranted(SchoolVoter::MANAGE, $school)) {
            return null;
        }

        $advisory = null !== $schoolYear && false === $school->getPlan()?->includesPrincipal()
            ? $this->sections->findAdvisory($user, $schoolYear)
            : null;
        if (null === $advisory || $advisory->getSchool()->getId() !== $school->getId()) {
            throw $this->createAccessDeniedException();
        }

        return $advisory;
    }

    /** A request of the current school year leaving $school (outgoing) or coming into it; anything else is 404. */
    private function find(School $school, int $id, bool $outgoing): TransferRequest
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $request = $this->requests->find($id);
        $side = $outgoing ? $request?->getFromSchool() : $request?->getToSchool();
        if (null === $request || null === $schoolYear
            || $request->getFromSection()->getSchoolYear()->getId() !== $schoolYear->getId()
            || $side->getId() !== $school->getId()) {
            throw $this->createNotFoundException('Transfer request not found.');
        }

        return $request;
    }

    /**
     * A request decided meanwhile (another tab, another approver) is not an access error: someone
     * who may open the transfers page is told so and sent back to it. Others still get 403.
     */
    private function refuseDecided(School $school, TransferRequest $request, User $user, string $tab): ?Response
    {
        if ($request->isPending()) {
            return null;
        }
        $this->adviserScope($school, $user, $this->schoolYears->findCurrent());
        $this->addFlash('error', 'This request was already decided.');

        return $this->backTo($school, $tab);
    }

    private function backTo(School $school, string $tab): Response
    {
        return $this->redirectToRoute('school_transfers', ['id' => $school->getId(), 'tab' => $tab]);
    }

    private function layout(): string
    {
        return match (true) {
            $this->isGranted(User::ROLE_ADMIN) => 'admin/_layout.html.twig',
            $this->isGranted(User::ROLE_PRINCIPAL) => 'principal/_layout.html.twig',
            default => 'teacher/_layout.html.twig',
        };
    }
}
