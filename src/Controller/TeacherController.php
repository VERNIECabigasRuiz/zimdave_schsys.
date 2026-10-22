<?php

namespace App\Controller;

use App\Entity\School;
use App\Entity\Section;
use App\Entity\User;
use App\Form\SectionFormType;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\SectionRepository;
use App\Service\SectionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The teacher's home. Class and grade pages come later. */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher')]
class TeacherController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly SectionRepository $sections,
        private readonly SectionManager $sectionManager,
    ) {
    }

    #[Route('', name: 'teacher_dashboard', methods: ['GET'])]
    public function dashboard(#[CurrentUser] User $user): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $school = $schoolYear ? $this->assignments->findSchoolOf($user, $schoolYear, User::ROLE_TEACHER) : null;
        $advisory = $schoolYear ? $this->sections->findAdvisory($user, $schoolYear) : null;

        return $this->render('teacher/dashboard.html.twig', [
            'school_year' => $schoolYear,
            'school' => $school,
            'advisory' => $advisory,
            'can_create_section' => null !== $school && null === $advisory && $this->ownsSections($school)
                && null === $this->sectionManager->refusal($school),
            'can_manage_subjects' => null !== $school && $this->ownsSections($school),
        ]);
    }

    /**
     * On a plan without a principal (Individual) the teacher creates their own
     * single section and is its adviser.
     */
    #[Route('/section', name: 'teacher_section_new', methods: ['GET', 'POST'])]
    public function createSection(Request $request, #[CurrentUser] User $user): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $school = $schoolYear ? $this->assignments->findSchoolOf($user, $schoolYear, User::ROLE_TEACHER) : null;
        if (null === $school) {
            return $this->redirectToRoute('teacher_dashboard');
        }
        if (!$this->ownsSections($school)) {
            throw $this->createAccessDeniedException('Your principal creates the sections of your school.');
        }
        if (null !== $advisory = $this->sections->findAdvisory($user, $schoolYear)) {
            $this->addFlash('error', \sprintf('You already advise %s.', $advisory->getLabel()));

            return $this->redirectToRoute('teacher_dashboard');
        }
        if (null !== $refusal = $this->sectionManager->refusal($school)) {
            $this->addFlash('error', $refusal);

            return $this->redirectToRoute('teacher_dashboard');
        }

        $section = (new Section($school, $schoolYear))->setAdviser($user);
        $form = $this->createForm(SectionFormType::class, $section, [
            'school' => $school,
            'school_year' => $schoolYear,
            'with_adviser' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->sectionManager->save($section);
            $this->addFlash('success', \sprintf('Created %s. You are its adviser.', $section->getLabel()));

            return $this->redirectToRoute('teacher_dashboard');
        }

        return $this->render('teacher/section.html.twig', [
            'form' => $form,
            'school' => $school,
            'strands' => SectionFormType::STRANDS,
        ]);
    }

    /** Teachers manage their own section only when the plan has no principal. */
    private function ownsSections(School $school): bool
    {
        return null !== $school->getPlan() && !$school->getPlan()->includesPrincipal();
    }
}
