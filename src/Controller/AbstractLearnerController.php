<?php

namespace App\Controller;

use App\Entity\Enrollment;
use App\Entity\Learner;
use App\Entity\Section;
use App\Entity\User;
use App\Form\EnrollSectionFormType;
use App\Form\LearnerFormType;
use App\Repository\EnrollmentRepository;
use App\Repository\LearnerRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\SectionRepository;
use App\Repository\TransferRequestRepository;
use App\Security\Voter\LearnerVoter;
use App\Service\EnrollmentException;
use App\Service\LearnerEnrollment;
use App\Service\LearnerLookup;
use App\Service\LookupKind;
use App\Service\PlanLimitException;
use App\Service\PlanLimits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Learners pages, shared by the school pages (admin, principal) and the adviser's
 * class pages. Subclasses decide the scope and routes and check who may open the page;
 * the actions here check each learner with LearnerVoter.
 */
abstract class AbstractLearnerController extends AbstractController
{
    public function __construct(
        protected readonly SchoolYearRepository $schoolYears,
        protected readonly SectionRepository $sections,
        protected readonly EnrollmentRepository $enrollments,
        protected readonly LearnerRepository $learners,
        protected readonly TransferRequestRepository $transfers,
        protected readonly LearnerEnrollment $enrollment,
        protected readonly LearnerLookup $lookup,
        protected readonly PlanLimits $planLimits,
        protected readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** Every learner enrolled at the school this year, searched by LRN or name and filtered by section. */
    protected function renderIndex(LearnerScope $scope, ?string $query, ?int $sectionId): Response
    {
        $section = null !== $sectionId ? $this->requireSection($scope, $sectionId) : null;

        return $this->render('learners/index.html.twig', $this->pageVars($scope) + [
            'enrollments' => $this->enrollments->search($scope->school, $scope->schoolYear, $query, $section),
            'sections' => $this->sections->findForSchool($scope->school, $scope->schoolYear),
            'count' => $this->enrollments->countForSchool($scope->school, $scope->schoolYear),
            'limit' => $scope->school->getStudentLimit(),
            'query' => $query,
            'section' => $section,
            'refusal' => $this->planLimits->blockedReason($scope->school),
        ]);
    }

    protected function renderClassList(LearnerScope $scope, Section $section): Response
    {
        $this->denyAccessUnlessGranted(LearnerVoter::VIEW_CLASS, $section);
        $plan = $scope->school->getPlan();

        return $this->render('learners/class_list.html.twig', $this->pageVars($scope) + [
            'section' => $section,
            'enrollments' => $this->enrollments->findClassList($section),
            // without a principal a section holds at most one seat's worth
            'limit' => false === $plan?->includesPrincipal() ? $plan->getStudentsPerSeat() : null,
        ]);
    }

    /** Step 1 asks for the LRN (query parameter `lrn`); step 2 shows what fits the learner found. */
    protected function handleEnroll(Request $request, LearnerScope $scope, User $user): Response
    {
        $lrn = trim($request->query->getString('lrn'));
        $vars = $this->pageVars($scope) + ['lrn' => $lrn, 'lrn_error' => null, 'result' => null, 'form' => null, 'transfer_form' => null];
        if ('' === $lrn) {
            return $this->render('learners/enroll.html.twig', $vars);
        }
        if (1 !== preg_match('/^\d{12}$/', $lrn)) {
            return $this->render('learners/enroll.html.twig', ['lrn_error' => 'The LRN must be exactly 12 digits.'] + $vars, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $result = $this->lookup->find($lrn, $scope->school, $scope->schoolYear);
        $vars['result'] = $result;
        $sections = null === $scope->fixedSection ? $this->sections->findForSchool($scope->school, $scope->schoolYear) : null;

        if (LookupKind::Here === $result->kind) {
            return $this->render('learners/enroll.html.twig', $vars);
        }
        if (LookupKind::Elsewhere === $result->kind) {
            if (null !== $sections && $this->isGranted(LearnerVoter::REQUEST_TRANSFER, $scope->school)) {
                $vars['transfer_form'] = $this->createForm(EnrollSectionFormType::class, null, [
                    'sections' => $sections,
                    'action' => $this->generateUrl('school_transfers_request', ['id' => $scope->school->getId(), 'lrn' => $lrn]),
                ]);
            }

            return $this->render('learners/enroll.html.twig', $vars);
        }

        $isNew = LookupKind::New === $result->kind;
        $learner = $isNew ? (new Learner(new User()))->setLrn($lrn) : $result->learner;
        $form = $this->createForm(EnrollSectionFormType::class, null, ['learner' => $isNew ? $learner : null, 'sections' => $sections]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $section = $scope->fixedSection ?? $form->get('section')->getData();
            $this->denyAccessUnlessGranted(LearnerVoter::ENROLL, $section);
            try {
                if ($isNew) {
                    $password = $this->enrollment->enrollNew($learner, $section, $user);
                    $this->addCredentials('Learner enrolled', $learner, $password);
                } else {
                    $this->enrollment->enrollExisting($learner, $section, $user);
                    $this->addFlash('success', \sprintf('Enrolled %s in %s.', $learner->getUser()->getFullName(), $section->getLabel()));
                }

                return $this->redirectToClassList($scope, $section);
            } catch (PlanLimitException|EnrollmentException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('learners/enroll.html.twig', ['form' => $form] + $vars);
    }

    protected function renderShow(LearnerScope $scope, int $id): Response
    {
        [$learner, $current] = $this->requireLearner($scope, $id, true);
        $here = $scope->covers($current);
        $canMove = $here && null === $scope->fixedSection && $this->isGranted(LearnerVoter::MOVE, $learner);

        return $this->render('learners/show.html.twig', $this->pageVars($scope) + [
            'learner' => $learner,
            'current' => $current,
            'history' => $this->enrollments->findHistory($learner),
            'transfers' => $this->transfers->findForLearner($learner),
            'move_sections' => $canMove ? array_values(array_filter(
                $this->sections->findForSchool($scope->school, $scope->schoolYear),
                static fn (Section $s) => $s !== $current->getSection(),
            )) : [],
            'can' => [
                'edit' => $this->isGranted(LearnerVoter::EDIT, $learner),
                'reset_password' => $this->isGranted(LearnerVoter::RESET_PASSWORD, $learner),
                'move' => $canMove,
                'unenroll' => $here && $this->isGranted(LearnerVoter::UNENROLL, $learner),
            ],
        ]);
    }

    protected function handleEdit(Request $request, LearnerScope $scope, int $id): Response
    {
        [$learner] = $this->requireLearner($scope, $id, true);
        $this->denyAccessUnlessGranted(LearnerVoter::EDIT, $learner);

        $oldLrn = $learner->getLrn();
        $form = $this->createForm(LearnerFormType::class, $learner, ['with_lrn' => $this->isGranted(LearnerVoter::CHANGE_LRN, $learner)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newLrn = $learner->getLrn();
            try {
                if ($newLrn !== $oldLrn) {
                    // changeLrn() logs the old and new LRN, so it starts from the old one
                    $learner->setLrn($oldLrn);
                    $this->enrollment->changeLrn($learner, $newLrn);
                }
                $this->enrollment->update($learner);
                $this->addFlash('success', \sprintf('Saved %s.', $learner->getUser()->getFullName()));

                return $this->redirectToShow($scope, $learner);
            } catch (PlanLimitException|EnrollmentException $e) {
                // the refused changes are still on the managed objects; drop them so nothing saves them later
                $this->entityManager->refresh($learner->getUser());
                $this->entityManager->refresh($learner);
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('learners/form.html.twig', $this->pageVars($scope) + [
            'form' => $form,
            'learner' => $learner,
        ]);
    }

    protected function handleMove(Request $request, LearnerScope $scope, int $id): Response
    {
        [$learner, $current] = $this->requireLearner($scope, $id, false);
        $this->denyAccessUnlessGranted(LearnerVoter::MOVE, $learner);
        $to = $this->requireSection($scope, $request->getPayload()->getInt('section'));

        try {
            $this->enrollment->move($current, $to);
            $this->addFlash('success', \sprintf('Moved %s to %s.', $learner->getUser()->getFullName(), $to->getLabel()));
        } catch (PlanLimitException|EnrollmentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToShow($scope, $learner);
    }

    protected function handleUnenroll(LearnerScope $scope, int $id): Response
    {
        [$learner, $current] = $this->requireLearner($scope, $id, false);
        $this->denyAccessUnlessGranted(LearnerVoter::UNENROLL, $learner);
        $section = $current->getSection();

        try {
            $this->enrollment->unenroll($current);
            $this->addFlash('success', \sprintf('Unenrolled %s from %s.', $learner->getUser()->getFullName(), $section->getLabel()));
        } catch (PlanLimitException|EnrollmentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToShow($scope, $learner);
        }

        return $this->redirectToRoute($scope->routes, $scope->routeParams);
    }

    protected function handleResetPassword(LearnerScope $scope, int $id): Response
    {
        [$learner] = $this->requireLearner($scope, $id, true);
        $this->denyAccessUnlessGranted(LearnerVoter::RESET_PASSWORD, $learner);

        try {
            $this->addCredentials('Password reset', $learner, $this->enrollment->resetPassword($learner));
        } catch (PlanLimitException|EnrollmentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToShow($scope, $learner);
    }

    /**
     * A learner this page manages: enrolled in its school (and section) this year. With
     * $allowHistory an administrator may open any learner, enrolled anywhere or nowhere.
     *
     * @return array{0: Learner, 1: Enrollment|null} the learner and their current enrollment
     */
    protected function requireLearner(LearnerScope $scope, int $id, bool $allowHistory): array
    {
        $learner = $this->learners->find($id);
        $current = null !== $learner ? $this->enrollments->findCurrent($learner, $scope->schoolYear) : null;
        if (null === $learner || (!$scope->covers($current) && !($allowHistory && $this->isGranted(User::ROLE_ADMIN)))) {
            throw $this->createNotFoundException('This learner is not enrolled here this school year.');
        }

        return [$learner, $current];
    }

    /** A section of the page's school this school year (the adviser's own, on their pages). */
    protected function requireSection(LearnerScope $scope, int $id): Section
    {
        $section = $this->sections->find($id);
        if (null === $section || $section->getSchool()->getId() !== $scope->school->getId()
            || $section->getSchoolYear()->getId() !== $scope->schoolYear->getId()
            || (null !== $scope->fixedSection && $section->getId() !== $scope->fixedSection->getId())) {
            throw $this->createNotFoundException('This section is not in the school this school year.');
        }

        return $section;
    }

    /** The class list: its own route on the school pages, the list itself on an adviser's. */
    protected function redirectToClassList(LearnerScope $scope, Section $section): Response
    {
        return null !== $scope->fixedSection
            ? $this->redirectToRoute($scope->routes, $scope->routeParams)
            : $this->redirectToRoute($scope->routes.'_class', $scope->routeParams + ['section' => $section->getId()]);
    }

    protected function redirectToShow(LearnerScope $scope, Learner $learner): Response
    {
        return $this->redirectToRoute($scope->routes.'_show', $scope->routeParams + ['learner' => $learner->getId()]);
    }

    /** @return array<string, mixed> */
    protected function pageVars(LearnerScope $scope): array
    {
        return [
            'school' => $scope->school,
            'school_year' => $scope->schoolYear,
            'fixed_section' => $scope->fixedSection,
            'layout' => $scope->layout,
            'routes' => $scope->routes,
            'route_params' => $scope->routeParams,
        ];
    }

    private function addCredentials(string $title, Learner $learner, string $password): void
    {
        $this->addFlash('credentials', [
            'title' => $title,
            'fullName' => $learner->getUser()->getFullName(),
            'username' => $learner->getUser()->getUsername(),
            'password' => $password,
        ]);
    }
}
