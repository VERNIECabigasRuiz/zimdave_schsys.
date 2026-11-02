<?php

namespace App\Controller;

use App\Entity\GradeChangeRequest;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Form\GradeChangeDeclineFormType;
use App\Grading\GradeChangeManager;
use App\Grading\GradeRecorder;
use App\Grading\GradingException;
use App\Repository\GradeChangeRequestRepository;
use App\Repository\SchoolYearRepository;
use App\Security\Voter\GradeVoter;
use App\Security\Voter\SchoolVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The school's grade change requests — admin for any school, principal for their own: those waiting, oldest first,
 * and those decided, newest first. Only the principal approves or declines.
 */
#[Route('/schools/{id}/grade-changes', requirements: ['id' => '\d+', 'request' => '\d+'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolGradeChangeController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly GradeChangeRequestRepository $requests,
        private readonly GradeChangeManager $manager,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[Route('', name: 'school_grade_changes', methods: ['GET'])]
    public function index(School $school, #[MapQueryParameter] ?string $status = null): Response
    {
        return $this->page($school, 'decided' === $status ? 'decided' : 'pending');
    }

    #[Route('/{request}/approve', name: 'school_grade_change_approve', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"grade-change-approve-" ~ args["request"]'), tokenKey: '_token')]
    public function approve(School $school, int $request, #[CurrentUser] User $user): Response
    {
        $change = $this->requireRequest($school, $request);
        $this->denyAccessUnlessGranted(GradeVoter::CHANGE_DECIDE, $change);

        try {
            $this->manager->approve($change, $user);
            $this->addFlash('success', 'Approved. The new grades are in.');
        } catch (GradingException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('school_grade_changes', ['id' => $school->getId()]);
    }

    #[Route('/{request}/decline', name: 'school_grade_change_decline', methods: ['POST'])]
    public function decline(Request $httpRequest, School $school, int $request, #[CurrentUser] User $user): Response
    {
        $change = $this->requireRequest($school, $request);
        $this->denyAccessUnlessGranted(GradeVoter::CHANGE_DECIDE, $change);
        if (!$change->isPending()) {
            // decided meanwhile: its decline form is gone from the page, so the note's errors have nowhere to show
            $this->addFlash('error', 'This request has already been decided.');

            return $this->redirectToRoute('school_grade_changes', ['id' => $school->getId()]);
        }

        $form = $this->declineForm($school, $change)->handleRequest($httpRequest);
        if (!$form->isSubmitted() || !$form->isValid()) {
            // show the field error where the principal typed the note
            return $this->page($school, 'pending', $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->manager->decline($change, $user, (string) $form->get('note')->getData());
            $this->addFlash('success', 'Declined. The teacher will see your note.');
        } catch (GradingException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('school_grade_changes', ['id' => $school->getId()]);
    }

    /** @param FormInterface|null $submitted a decline form posted with errors, shown in place of its blank one */
    private function page(School $school, string $status, ?FormInterface $submitted = null, int $code = Response::HTTP_OK): Response
    {
        $schoolYear = $this->currentYear();
        $requests = 'decided' === $status
            ? $this->requests->findDecidedForSchool($school, $schoolYear)
            : $this->requests->findPendingForSchool($school, $schoolYear);

        $labels = [];
        $forms = [];
        foreach ($requests as $change) {
            $labels[$change->getId()] = GradeRecorder::classLabel($change->getSection(), $change->getSubject());
            if ($change->isPending() && $this->isGranted(GradeVoter::CHANGE_DECIDE, $change)) {
                $form = null !== $submitted && $submitted->getName() === 'decline_'.$change->getId() ? $submitted : $this->declineForm($school, $change);
                $forms[$change->getId()] = $form->createView();
            }
        }

        return $this->render('grades/changes.html.twig', [
            'layout' => $this->layout(),
            'school' => $school,
            'school_year' => $schoolYear,
            'status' => $status,
            'requests' => $requests,
            'class_labels' => $labels,
            'decline_forms' => $forms,
            'pending_count' => 'pending' === $status ? \count($requests) : $this->requests->countPendingForSchool($school, $schoolYear),
        ], new Response(status: $code));
    }

    private function declineForm(School $school, GradeChangeRequest $change): FormInterface
    {
        return $this->formFactory->createNamed('decline_'.$change->getId(), GradeChangeDeclineFormType::class, null, [
            'action' => $this->generateUrl('school_grade_change_decline', ['id' => $school->getId(), 'request' => $change->getId()]),
        ]);
    }

    private function requireRequest(School $school, int $id): GradeChangeRequest
    {
        $change = $this->requests->find($id);
        if (null === $change || $change->getSection()->getSchool()->getId() !== $school->getId()) {
            throw $this->createNotFoundException();
        }

        return $change;
    }

    private function currentYear(): SchoolYear
    {
        return $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');
    }

    private function layout(): string
    {
        return $this->isGranted(User::ROLE_ADMIN) ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig';
    }
}
