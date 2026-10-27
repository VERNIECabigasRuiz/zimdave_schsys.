<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserAccountFormType;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\UserRepository;
use App\Service\ActivityLogger;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Form\TeacherSchoolFormType;
use App\Service\AssignmentException;
use App\Service\PlanLimitException;
use App\Service\SchoolTeacherManager;
use App\Service\PlanLimits;
use App\Service\SchoolTeacherLock;
use App\Service\TemporaryPasswordGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin')]
class UserAccountController extends AbstractController
{
    /** URL slug => role, for the PRINCIPAL / TEACHER / STUDENT menu items. */
    public const ROLE_SLUGS = [
        'admins' => User::ROLE_ADMIN,
        'principals' => User::ROLE_PRINCIPAL,
        'teachers' => User::ROLE_TEACHER,
        'students' => User::ROLE_STUDENT,
    ];

    private const PER_PAGE = 20;

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityLogger $activityLogger,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TemporaryPasswordGenerator $passwordGenerator,
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly PlanLimits $planLimits,
        private readonly SchoolTeacherLock $teacherLock,
    ) {
    }

    #[Route('/accounts', name: 'admin_accounts', methods: ['GET'])]
    public function index(
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] ?string $status = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        return $this->renderList(null, $q, $status, $page);
    }

    #[Route('/{slug}', name: 'admin_accounts_by_role', requirements: ['slug' => 'admins|principals|teachers|students'], methods: ['GET'])]
    public function byRole(
        string $slug,
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] ?string $status = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        return $this->renderList($slug, $q, $status, $page);
    }

    #[Route('/accounts/new', name: 'admin_account_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[MapQueryParameter] ?string $role = null): Response
    {
        $user = new User();
        if (null !== $role && isset(self::ROLE_SLUGS[$role])) {
            $user->setRole(self::ROLE_SLUGS[$role]);
        }

        $form = $this->createForm(UserAccountFormType::class, $user, [
            'suggested_password' => $this->passwordGenerator->generate(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $temporaryPassword = $form->get('temporaryPassword')->getData();
            $user->setPassword($this->passwordHasher->hashPassword($user, $temporaryPassword));
            $user->setMustChangePassword(true);

            $this->entityManager->persist($user);
            $this->activityLogger->log('Created account: '.$user->getUsername());
            $this->entityManager->flush();

            $this->addFlash('credentials', [
                'title' => 'Account created',
                'fullName' => $user->getFullName(),
                'username' => $user->getUsername(),
                'password' => $temporaryPassword,
            ]);

            if ($request->request->has('save_and_new')) {
                return $this->redirectToRoute('admin_account_new', ['role' => array_search($user->getRole(), self::ROLE_SLUGS, true)]);
            }

            return $this->redirectToRoute('admin_accounts_by_role', ['slug' => array_search($user->getRole(), self::ROLE_SLUGS, true)]);
        }

        return $this->render('admin/accounts/form.html.twig', [
            'form' => $form,
            'account' => null,
        ]);
    }

    #[Route('/accounts/{id}/edit', name: 'admin_account_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, User $account): Response
    {
        $form = $this->createForm(UserAccountFormType::class, $account, [
            'is_new' => false,
            'lock_role' => $this->isSelf($account),
        ]);
        $originalRole = $account->getRole();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->guardPrincipalRoleChange($form, $account, $originalRole);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->activityLogger->log('Updated account: '.$account->getUsername());
            $this->entityManager->flush();

            $this->addFlash('success', \sprintf('Saved changes to %s.', $account->getFullName()));

            return $this->redirectToRoute('admin_accounts_by_role', ['slug' => array_search($account->getRole(), self::ROLE_SLUGS, true)]);
        }

        return $this->render('admin/accounts/form.html.twig', [
            'form' => $form,
            'account' => $account,
        ]);
    }

    #[Route('/accounts/{id}/toggle-active', name: 'admin_account_toggle_active', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"account-" ~ args["account"].getId()'), tokenKey: '_token')]
    public function toggleActive(Request $request, User $account): Response
    {
        if ($this->isSelf($account)) {
            $this->addFlash('error', 'You cannot deactivate your own account.');
        } else {
            $toggle = function () use ($account): void {
                // Reactivating a teacher takes a seat of their school's plan this school year
                if (!$account->isActive() && null !== $refusal = $this->teacherSeatRefusal($account)) {
                    throw new PlanLimitException($refusal);
                }
                $account->setActive(!$account->isActive());
                $this->activityLogger->log(($account->isActive() ? 'Activated' : 'Deactivated').' account: '.$account->getUsername());
                $this->entityManager->flush();
            };

            try {
                $school = $this->teacherSchool($account);
                null !== $school ? $this->teacherLock->run($school, $toggle) : $toggle();
                $this->addFlash('success', \sprintf('%s is now %s.', $account->getFullName(), $account->isActive() ? 'active' : 'inactive'));
            } catch (PlanLimitException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectBack($request);
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

    #[Route('/accounts/{id}/reset-password', name: 'admin_account_reset_password', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"account-" ~ args["account"].getId()'), tokenKey: '_token')]
    public function resetPassword(Request $request, User $account): Response
    {
        if ($this->isSelf($account)) {
            $this->addFlash('error', 'Use "Change password" to change your own password.');

            return $this->redirectBack($request);
        }

        $temporaryPassword = $this->passwordGenerator->generate();
        $account->setPassword($this->passwordHasher->hashPassword($account, $temporaryPassword));
        $account->setMustChangePassword(true);
        $this->activityLogger->log('Reset password: '.$account->getUsername());
        $this->entityManager->flush();

        $this->addFlash('credentials', [
            'title' => 'Password reset',
            'fullName' => $account->getFullName(),
            'username' => $account->getUsername(),
            'password' => $temporaryPassword,
        ]);

        return $this->redirectBack($request);
    }

    private function renderList(?string $slug, ?string $q, ?string $status, int $page): Response
    {
        $role = null !== $slug ? self::ROLE_SLUGS[$slug] : null;
        $active = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };
        $page = max(1, $page);

        $accounts = $this->users->search($q, $role, $active, $page, self::PER_PAGE);
        $total = \count($accounts);

        $currentYear = $this->schoolYears->findCurrent();

        return $this->render('admin/accounts/index.html.twig', [
            'accounts' => $accounts,
            'school_names' => $currentYear ? $this->assignments->findSchoolNamesByUser($currentYear) : [],
            'slug' => $slug,
            'role' => $role,
            'q' => $q,
            'status' => $status,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }

    /**
     * A principal still heading a school this (or a coming) school year must be removed there first.
     */
    private function guardPrincipalRoleChange(FormInterface $form, User $account, ?string $originalRole): void
    {
        if (User::ROLE_PRINCIPAL !== $originalRole || User::ROLE_PRINCIPAL === $account->getRole()) {
            return;
        }

        $assignment = $this->assignments->findUnfinishedPrincipalAssignment($account);
        if (null !== $assignment) {
            $form->get('role')->addError(new FormError(\sprintf(
                'Remove %s as principal of %s (%s) before changing the role.',
                $account->getFullName(),
                $assignment->getSchool()->getName(),
                $assignment->getSchoolYear()->getName(),
            )));
        }
    }

    private function requireTeacherAndYear(User $account): SchoolYear
    {
        if (User::ROLE_TEACHER !== $account->getRole()) {
            throw $this->createNotFoundException('Only teacher accounts are assigned to a school here.');
        }

        return $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');
    }

    /**
     * The school where $account teaches this school year, if they are a teacher.
     */
    private function teacherSchool(User $account): ?School
    {
        $currentYear = $this->schoolYears->findCurrent();
        if (User::ROLE_TEACHER !== $account->getRole() || null === $currentYear) {
            return null;
        }

        return $this->assignments->findSchoolOf($account, $currentYear, User::ROLE_TEACHER);
    }

    private function teacherSeatRefusal(User $account): ?string
    {
        $school = $this->teacherSchool($account);

        return $school ? $this->planLimits->canAddTeacher($school, $this->schoolYears->findCurrent()) : null;
    }

    private function isSelf(User $account): bool
    {
        return $account->getUserIdentifier() === $this->getUser()?->getUserIdentifier();
    }

    private function redirectBack(Request $request): Response
    {
        $back = $request->request->getString('_back');

        // Only follow local paths, never another site
        if (str_starts_with($back, '/') && !str_starts_with($back, '//')) {
            return $this->redirect($back);
        }

        return $this->redirectToRoute('admin_accounts');
    }
}
