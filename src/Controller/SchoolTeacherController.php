<?php

namespace App\Controller;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Form\ExistingTeacherFormType;
use App\Form\TeacherFormType;
use App\Entity\PersonalDataSheet;
use App\Repository\PersonalDataSheetRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Service\PdsPdfRenderer;
use Symfony\Component\HttpFoundation\HeaderUtils;
use App\Repository\SchoolYearRepository;
use App\Repository\UserRepository;
use App\Service\AssignmentException;
use Symfony\Component\Form\FormError;
use App\Security\Voter\SchoolVoter;
use App\Service\ActivityLogger;
use App\Service\PlanLimitException;
use App\Service\PlanLimits;
use App\Service\SchoolTeacherLock;
use App\Service\SchoolTeacherManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The teachers of one school in the current school year. Shared by administrators
 * (any school) and principals (their own school).
 */
#[Route('/schools/{id}/teachers', requirements: ['id' => '\d+'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolTeacherController extends AbstractController
{
    public function __construct(
        private readonly SchoolTeacherManager $teachers,
        private readonly PlanLimits $planLimits,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly SchoolYearRepository $schoolYears,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityLogger $activityLogger,
        private readonly SchoolTeacherLock $lock,
    ) {
    }

    #[Route('', name: 'school_teachers', methods: ['GET'])]
    public function index(School $school): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $previous = $schoolYear ? $this->schoolYears->findPrevious($schoolYear) : null;

        return $this->render('school_teachers/index.html.twig', [
            'school' => $school,
            'school_year' => $schoolYear,
            'assignments' => $schoolYear ? $this->assignments->findTeacherAssignments($school, $schoolYear) : [],
            'used' => $schoolYear ? $this->planLimits->countTeachers($school, $schoolYear) : 0,
            'add_refusal' => $schoolYear ? $this->planLimits->canAddTeacher($school, $schoolYear) : null,
            'bring_back_from' => $previous && [] !== $this->assignments->findTeachersToBringBack($school, $previous, $schoolYear) ? $previous : null,
        ]);
    }

    #[Route('/new', name: 'school_teachers_new', methods: ['GET', 'POST'])]
    public function new(Request $request, School $school): Response
    {
        $schoolYear = $this->requireSchoolYear();
        if (null !== $refusal = $this->planLimits->canAddTeacher($school, $schoolYear)) {
            $this->addFlash('error', $refusal);

            return $this->redirectToList($school);
        }

        $teacher = new User();
        $form = $this->createForm(TeacherFormType::class, $teacher);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $password = $this->lock->run($school, function () use ($school, $schoolYear, $teacher): string {
                    $password = $this->teachers->create($school, $schoolYear, $teacher);
                    $this->entityManager->flush();

                    return $password;
                });
                $this->addCredentials('Teacher created', $teacher, $password);
            } catch (PlanLimitException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToList($school);
        }

        return $this->render('school_teachers/form.html.twig', [
            'form' => $form,
            'school' => $school,
            'teacher' => null,
        ]);
    }

    #[Route('/add-existing', name: 'school_teachers_add_existing', methods: ['GET', 'POST'])]
    public function addExisting(Request $request, School $school, UserRepository $users): Response
    {
        $schoolYear = $this->requireSchoolYear();
        if (null !== $refusal = $this->planLimits->canAddTeacher($school, $schoolYear)) {
            $this->addFlash('error', $refusal);

            return $this->redirectToList($school);
        }

        $form = $this->createForm(ExistingTeacherFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $username = mb_strtolower(trim($form->get('username')->getData()));
            $teacher = $users->findOneBy(['username' => $username]);

            try {
                if (null === $teacher) {
                    throw new AssignmentException('No teacher account with that username.');
                }
                $this->lock->run($school, function () use ($school, $schoolYear, $teacher): void {
                    $this->teachers->addExisting($school, $schoolYear, $teacher);
                    $this->entityManager->flush();
                });
                $this->addFlash('success', \sprintf('%s was added to the teachers of %s.', $teacher->getFullName(), $school->getName()));

                return $this->redirectToList($school);
            } catch (AssignmentException|PlanLimitException $e) {
                $form->get('username')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('school_teachers/add_existing.html.twig', [
            'form' => $form,
            'school' => $school,
        ]);
    }

    #[Route('/{teacher}/edit', name: 'school_teachers_edit', requirements: ['teacher' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, School $school, int $teacher): Response
    {
        $user = $this->requireTeacher($school, $teacher);
        $form = $this->createForm(TeacherFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->activityLogger->log('Updated teacher '.$user->getUsername());
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Saved changes to %s.', $user->getFullName()));

            return $this->redirectToList($school);
        }

        return $this->render('school_teachers/form.html.twig', [
            'form' => $form,
            'school' => $school,
            'teacher' => $user,
        ]);
    }

    /** The teacher's PDS as a PDF, for the school's file. Never writes the teacher's data. */
    #[Route('/{teacher}/pds.pdf', name: 'school_teacher_pds', requirements: ['teacher' => '\d+'], methods: ['GET'])]
    public function pds(School $school, int $teacher, PersonalDataSheetRepository $sheets, PdsPdfRenderer $renderer): Response
    {
        $user = $this->requireTeacher($school, $teacher);
        $sheet = $sheets->findOneBy(['user' => $user]) ?? new PersonalDataSheet($user);

        $this->activityLogger->log('Downloaded PDS of '.$user->getUsername());
        $this->entityManager->flush();

        return new Response($renderer->render($sheet), headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $renderer->filename($sheet)),
        ]);
    }

    #[Route('/{teacher}/toggle', name: 'school_teachers_toggle', requirements: ['teacher' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"teacher-" ~ args["teacher"]'), tokenKey: '_token')]
    public function toggle(School $school, int $teacher): Response
    {
        $user = $this->requireTeacher($school, $teacher);

        try {
            $this->lock->run($school, function () use ($school, $user): void {
                if ($user->isActive()) {
                    $this->teachers->deactivate($user);
                } else {
                    $this->teachers->activate($school, $this->requireSchoolYear(), $user);
                }
                $this->entityManager->flush();
            });
            $this->addFlash('success', \sprintf('%s is now %s.', $user->getFullName(), $user->isActive() ? 'active' : 'inactive'));
        } catch (PlanLimitException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToList($school);
    }

    #[Route('/{teacher}/reset-password', name: 'school_teachers_reset_password', requirements: ['teacher' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"teacher-" ~ args["teacher"]'), tokenKey: '_token')]
    public function resetPassword(School $school, int $teacher): Response
    {
        $user = $this->requireTeacher($school, $teacher);

        $password = $this->teachers->resetPassword($user);
        $this->entityManager->flush();
        $this->addCredentials('Password reset', $user, $password);

        return $this->redirectToList($school);
    }

    #[Route('/bring-back', name: 'school_teachers_bring_back', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"bring-back-" ~ args["school"].getId()'), tokenKey: '_token')]
    public function bringBack(School $school): Response
    {
        try {
            $result = $this->lock->run($school, function () use ($school) {
                $result = $this->teachers->bringBack($school, $this->requireSchoolYear());
                $this->entityManager->flush();

                return $result;
            });

            $this->addFlash('success', \sprintf('Brought back %d teacher(s).', $result->added));
            if ([] !== $result->skipped) {
                $this->addFlash('warning', 'Not brought back: '.implode('; ', $result->skipped));
            }
        } catch (PlanLimitException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToList($school);
    }

    private function requireSchoolYear(): SchoolYear
    {
        return $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');
    }

    private function requireTeacher(School $school, int $userId): User
    {
        return $this->teachers->findTeacher($school, $this->requireSchoolYear(), $userId)
            ?? throw $this->createNotFoundException('This teacher does not belong to the school this school year.');
    }

    private function addCredentials(string $title, User $user, string $password): void
    {
        $this->addFlash('credentials', [
            'title' => $title,
            'fullName' => $user->getFullName(),
            'username' => $user->getUsername(),
            'password' => $password,
        ]);
    }

    private function redirectToList(School $school): Response
    {
        return $this->redirectToRoute('school_teachers', ['id' => $school->getId()]);
    }
}
