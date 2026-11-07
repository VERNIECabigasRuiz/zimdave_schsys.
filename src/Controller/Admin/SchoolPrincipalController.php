<?php

namespace App\Controller\Admin;

use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Access\Domain\Model\User;
use App\Form\PrincipalAssignmentFormType;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Service\AssignmentException;
use App\Service\PrincipalAssigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The admin assigns principals to schools per school year. */
#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin/schools')]
class SchoolPrincipalController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly EntityManagerInterface $entityManager,
        private readonly PrincipalAssigner $principalAssigner,
    ) {
    }

    #[Route('/{id}/principal', name: 'admin_schools_principal', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function principal(Request $request, School $school, #[MapQueryParameter] ?int $sy = null): Response
    {
        $schoolYear = $this->requireSchoolYear($sy);
        $current = $this->assignments->findPrincipal($school, $schoolYear);

        $form = $this->createForm(PrincipalAssignmentFormType::class, ['principal' => $current?->getUser()], [
            'principals' => $this->assignments->findAssignablePrincipals($schoolYear, $current?->getUser()),
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
    private function requireSchoolYear(?int $id): SchoolYear
    {
        if (null === $id) {
            return $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('Create a school year first.');
        }

        return $this->schoolYears->findById($id) ?? throw $this->createNotFoundException('School year not found.');
    }

    private function redirectToList(SchoolYear $schoolYear): Response
    {
        return $this->redirectToRoute('admin_schools', ['sy' => $schoolYear->getId()]);
    }
}
