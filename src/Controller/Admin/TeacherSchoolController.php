<?php

namespace App\Controller\Admin;

use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Form\TeacherSchoolFormType;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Service\AssignmentException;
use App\Service\PlanLimitException;
use App\Service\SchoolTeacherLock;
use App\Service\SchoolTeacherManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * The admin assigns a teacher account to a school for the current school year.
 */
#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin')]
class TeacherSchoolController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly SchoolTeacherLock $teacherLock,
    ) {
    }

    #[Route('/accounts/{id}/school', name: 'admin_account_school', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function school(Request $request, User $account, SchoolTeacherManager $teachers): Response
    {
        $schoolYear = $this->requireTeacherAndYear($account);
        $current = $this->assignments->findSchoolOf($account, $schoolYear, User::ROLE_TEACHER);

        $form = $this->createForm(TeacherSchoolFormType::class, ['school' => $current]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var School $school */
            $school = $form->get('school')->getData();

            try {
                $this->teacherLock->run($school, function () use ($teachers, $school, $schoolYear, $account): void {
                    $teachers->assignToSchool($school, $schoolYear, $account);
                    $this->entityManager->flush();
                });
                $this->addFlash('success', \sprintf('%s teaches at %s for %s.', $account->getFullName(), $school->getName(), $schoolYear->getName()));

                return $this->redirectToRoute('admin_accounts_by_role', ['slug' => 'teachers']);
            } catch (AssignmentException|PlanLimitException $e) {
                $form->get('school')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('admin/accounts/school.html.twig', [
            'form' => $form,
            'account' => $account,
            'school_year' => $schoolYear,
            'current' => $current,
        ]);
    }

    #[Route('/accounts/{id}/school/remove', name: 'admin_account_school_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"account-school-" ~ args["account"].getId()'), tokenKey: '_token')]
    public function removeSchool(User $account, SchoolTeacherManager $teachers): Response
    {
        $schoolYear = $this->requireTeacherAndYear($account);

        try {
            $teachers->removeFromSchool($schoolYear, $account);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('%s no longer has a school for %s.', $account->getFullName(), $schoolYear->getName()));
        } catch (AssignmentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_accounts_by_role', ['slug' => 'teachers']);
    }

    private function requireTeacherAndYear(User $account): SchoolYear
    {
        if (User::ROLE_TEACHER !== $account->getRole()) {
            throw $this->createNotFoundException('Only teacher accounts are assigned to a school here.');
        }

        return $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');
    }
}
