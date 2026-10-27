<?php

namespace App\Controller;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Form\SectionFormType;
use App\Repository\SchoolYearRepository;
use App\Repository\SectionRepository;
use App\Security\Voter\SchoolVoter;
use App\Service\InUseException;
use App\Service\PlanLimitException;
use App\Service\SectionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Class sections of one school in the current school year. Shared by administrators
 * (any school) and principals (their own school).
 */
#[Route('/schools/{id}/sections', requirements: ['id' => '\d+'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolSectionController extends AbstractController
{
    public function __construct(
        private readonly SectionRepository $sections,
        private readonly SchoolYearRepository $schoolYears,
        private readonly SectionManager $manager,
    ) {
    }

    #[Route('', name: 'school_sections', methods: ['GET'])]
    public function index(School $school): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $previous = $schoolYear ? $this->schoolYears->findPrevious($schoolYear) : null;

        $groups = [];
        foreach ($schoolYear ? $this->sections->findForSchool($school, $schoolYear) : [] as $section) {
            $groups[$section->getGradeLevel()->label()][] = $section;
        }

        return $this->render('school_sections/index.html.twig', [
            'school' => $school,
            'school_year' => $schoolYear,
            'groups' => $groups,
            'refusal' => $this->manager->refusal($school),
            'bring_back_from' => $previous && [] !== $this->sections->findBringBackCandidates($school, $previous, $schoolYear) ? $previous : null,
        ]);
    }

    #[Route('/new', name: 'school_sections_new', methods: ['GET', 'POST'])]
    public function new(Request $request, School $school): Response
    {
        return $this->editForm($request, new Section($school, $this->requireSchoolYear()));
    }

    #[Route('/{section}/edit', name: 'school_sections_edit', requirements: ['section' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, School $school, int $section): Response
    {
        return $this->editForm($request, $this->requireSection($school, $section));
    }

    #[Route('/{section}/delete', name: 'school_sections_delete', requirements: ['section' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"section-" ~ args["section"]'), tokenKey: '_token')]
    public function delete(School $school, int $section): Response
    {
        $section = $this->requireSection($school, $section);
        try {
            $this->manager->delete($section);
            $this->addFlash('success', \sprintf('Deleted %s.', $section->getLabel()));
        } catch (PlanLimitException|InUseException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToList($school);
    }

    #[Route('/bring-back', name: 'school_sections_bring_back', methods: ['POST'])]
    #[IsCsrfTokenValid('bring-back-sections', tokenKey: '_token')]
    public function bringBack(School $school): Response
    {
        $schoolYear = $this->requireSchoolYear();
        $previous = $this->schoolYears->findPrevious($schoolYear) ?? throw $this->createNotFoundException('There is no previous school year.');
        try {
            $count = $this->manager->bringBack($school, $previous, $schoolYear);
            $this->addFlash('success', \sprintf('Brought back %d section(s) from S.Y. %s. Assign their advisers.', $count, $previous->getName()));
        } catch (PlanLimitException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToList($school);
    }

    private function editForm(Request $request, Section $section): Response
    {
        $school = $section->getSchool();
        if (null !== $refusal = $this->manager->refusal($school)) {
            $this->addFlash('error', $refusal);

            return $this->redirectToList($school);
        }

        $previousAdviser = $section->getAdviser();
        $form = $this->createForm(SectionFormType::class, $section, [
            'school' => $school,
            'school_year' => $section->getSchoolYear(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $isNew = null === $section->getId();
            try {
                $this->manager->save($section, $previousAdviser);
                $this->addFlash('success', \sprintf($isNew ? 'Created %s.' : 'Saved %s.', $section->getLabel()));
            } catch (PlanLimitException|InUseException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToList($school);
        }

        return $this->render('school_sections/form.html.twig', [
            'form' => $form,
            'school' => $school,
            'section' => $section,
            'strands' => SectionFormType::STRANDS,
        ]);
    }

    private function requireSchoolYear(): SchoolYear
    {
        return $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');
    }

    /** A section of this school in the current school year. */
    private function requireSection(School $school, int $id): Section
    {
        $section = $this->sections->find($id);
        if (null === $section || $section->getSchool() !== $school || $section->getSchoolYear() !== $this->requireSchoolYear()) {
            throw $this->createNotFoundException('This section is not in the school this school year.');
        }

        return $section;
    }

    private function redirectToList(School $school): Response
    {
        return $this->redirectToRoute('school_sections', ['id' => $school->getId()]);
    }
}
