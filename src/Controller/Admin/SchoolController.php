<?php

namespace App\Controller\Admin;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Form\PrincipalAssignmentFormType;
use App\Form\SchoolFormType;
use App\Repository\PlanRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\UserRepository;
use App\Service\ActivityLogger;
use App\Service\AssignmentException;
use App\Service\PrincipalAssigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin/schools')]
class SchoolController extends AbstractController
{
    public function __construct(
        private readonly SchoolRepository $schools,
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityLogger $activityLogger,
        private readonly PrincipalAssigner $principalAssigner,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'admin_schools', methods: ['GET'])]
    public function index(#[MapQueryParameter] ?int $sy = null): Response
    {
        $schoolYear = $this->resolveSchoolYear($sy);
        $principals = $schoolYear ? $this->assignments->findPrincipalsBySchool($schoolYear) : [];

        return $this->render('admin/schools/index.html.twig', [
            'schools' => $this->schools->findAllOrdered(),
            'school_years' => $this->schoolYears->findAllNewestFirst(),
            'school_year' => $schoolYear,
            'principals' => $principals,
            'teacher_counts' => $schoolYear ? $this->assignments->countTeachersBySchool($schoolYear) : [],
            'today' => $this->clock->now(),
            // Offered only while the selected year has no principals yet
            'previous_year' => $schoolYear && [] === $principals ? $this->schoolYears->findPrevious($schoolYear) : null,
        ]);
    }

    #[Route('/new', name: 'admin_schools_new', methods: ['GET', 'POST'])]
    public function new(Request $request, PlanRepository $plans): Response
    {
        $school = (new School())->setPlan($plans->findByName('Basic'));
        $form = $this->createForm(SchoolFormType::class, $school);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($school);
            $this->activityLogger->log('Created school '.$this->describe($school));
            $this->entityManager->flush();

            $this->addFlash('success', \sprintf('%s created.', $school->getName()));

            return $this->redirectToRoute('admin_schools');
        }

        return $this->render('admin/schools/form.html.twig', [
            'form' => $form,
            'school' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_schools_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, School $school): Response
    {
        $form = $this->createForm(SchoolFormType::class, $school);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->guardPlanWithoutPrincipal($form, $school);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->activityLogger->log('Updated school '.$this->describe($school));
            $this->entityManager->flush();

            $this->addFlash('success', \sprintf('%s saved.', $school->getName()));

            return $this->redirectToRoute('admin_schools');
        }

        return $this->render('admin/schools/form.html.twig', [
            'form' => $form,
            'school' => $school,
        ]);
    }

    #[Route('/{id}/toggle', name: 'admin_schools_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"school-" ~ args["school"].getId()'), tokenKey: '_token')]
    public function toggle(School $school): Response
    {
        $school->setActive(!$school->isActive());
        $this->activityLogger->log(\sprintf('%s school %s', $school->isActive() ? 'Activated' : 'Deactivated', $this->describe($school)));
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('%s is now %s.', $school->getName(), $school->isActive() ? 'active' : 'inactive'));

        return $this->redirectToRoute('admin_schools');
    }

    #[Route('/{id}/principal', name: 'admin_schools_principal', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function principal(Request $request, School $school, UserRepository $users, #[MapQueryParameter] ?int $sy = null): Response
    {
        $schoolYear = $this->requireSchoolYear($sy);
        $current = $this->assignments->findPrincipal($school, $schoolYear);

        $form = $this->createForm(PrincipalAssignmentFormType::class, ['principal' => $current?->getUser()], [
            'principals' => $users->findAssignablePrincipals($schoolYear, $current?->getUser()),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $principal */
            $principal = $form->get('principal')->getData();

            try {
                $this->principalAssigner->assign($school, $schoolYear, $principal);
                $this->entityManager->flush();
                $this->addFlash('success', \sprintf(
                    '%s is now the principal of %s for %s.',
                    $principal->getFullName(),
                    $school->getName(),
                    $schoolYear->getName(),
                ));
            } catch (AssignmentException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToList($schoolYear);
        }

        return $this->render('admin/schools/assign_principal.html.twig', [
            'form' => $form,
            'school' => $school,
            'school_year' => $schoolYear,
            'current' => $current,
        ]);
    }

    #[Route('/{id}/principal/remove', name: 'admin_schools_principal_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"school-principal-" ~ args["school"].getId()'), tokenKey: '_token')]
    public function removePrincipal(School $school, #[MapQueryParameter] ?int $sy = null): Response
    {
        $schoolYear = $this->requireSchoolYear($sy);

        $this->principalAssigner->remove($school, $schoolYear);
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('%s no longer has a principal for %s.', $school->getName(), $schoolYear->getName()));

        return $this->redirectToList($schoolYear);
    }

    #[Route('/copy-principals', name: 'admin_schools_copy_principals', methods: ['POST'])]
    #[IsCsrfTokenValid('copy-principals', tokenKey: '_token')]
    public function copyPrincipals(#[MapQueryParameter] ?int $sy = null): Response
    {
        $schoolYear = $this->requireSchoolYear($sy);

        try {
            $result = $this->principalAssigner->copyFromPrevious($schoolYear);
            $this->entityManager->flush();

            $this->addFlash('success', \sprintf('Copied %d principal assignment(s).', $result->copied));
            if ([] !== $result->skipped) {
                $this->addFlash('warning', 'Skipped: '.implode('; ', $result->skipped));
            }
        } catch (AssignmentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToList($schoolYear);
    }

    /**
     * The school year picked in ?sy=, or the current one when none is picked.
     */
    private function resolveSchoolYear(?int $id): ?SchoolYear
    {
        if (null === $id) {
            return $this->schoolYears->findCurrent();
        }

        return $this->schoolYears->find($id) ?? throw $this->createNotFoundException('School year not found.');
    }

    private function requireSchoolYear(?int $id): SchoolYear
    {
        return $this->resolveSchoolYear($id) ?? throw $this->createNotFoundException('Create a school year first.');
    }

    private function redirectToList(SchoolYear $schoolYear): Response
    {
        return $this->redirectToRoute('admin_schools', ['sy' => $schoolYear->getId()]);
    }

    /**
     * A plan without a principal can only be chosen once the school has no principal this school year.
     */
    private function guardPlanWithoutPrincipal(FormInterface $form, School $school): void
    {
        $plan = $school->getPlan();
        $currentYear = $this->schoolYears->findCurrent();
        if (null === $plan || $plan->includesPrincipal() || null === $currentYear) {
            return;
        }

        if (null !== $this->assignments->findPrincipal($school, $currentYear)) {
            $form->get('plan')->addError(new FormError(\sprintf(
                'Remove the principal of %s for %s before choosing the %s plan.',
                $school->getName(),
                $currentYear->getName(),
                $plan->getName(),
            )));
        }
    }

    private function describe(School $school): string
    {
        return $school->getSchoolId().' '.$school->getName();
    }
}
