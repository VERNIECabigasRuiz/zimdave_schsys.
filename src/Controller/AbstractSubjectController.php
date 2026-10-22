<?php

namespace App\Controller;

use App\Data\DepEdSubjects;
use App\Entity\GradeLevel;
use App\Entity\Subject;
use App\Form\SectionFormType;
use App\Form\SubjectFormType;
use App\Repository\SchoolYearRepository;
use App\Repository\SubjectRepository;
use App\Service\PlanLimitException;
use App\Service\SubjectManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Subjects pages, shared by the school page (admin, principal) and the
 * Individual-plan teacher's own page. Subclasses decide the scope and routes.
 */
abstract class AbstractSubjectController extends AbstractController
{
    public function __construct(
        protected readonly SubjectRepository $subjects,
        protected readonly SubjectManager $manager,
        protected readonly SchoolYearRepository $schoolYears,
    ) {
    }

    protected function renderList(SubjectScope $scope): Response
    {
        $groups = [];
        $used = [];
        foreach ($this->subjects->findForSchool($scope->school, $scope->schoolYear) as $subject) {
            if (!$scope->covers($subject->getGradeLevel())) {
                continue;
            }
            $used[$subject->getGradeLevel()->value] = true;
            if (!$subject->isComponent()) {
                $groups[self::groupLabel($subject)][] = $subject;
            }
        }

        $previous = $this->schoolYears->findPrevious($scope->schoolYear);
        $candidates = $previous ? array_filter(
            $this->subjects->findBringBackCandidates($scope->school, $previous, $scope->schoolYear),
            static fn (Subject $s) => $scope->covers($s->getGradeLevel()),
        ) : [];

        return $this->render('school_subjects/index.html.twig', $this->pageVars($scope) + [
            'groups' => $groups,
            'refusal' => $this->manager->refusal($scope->school),
            'deped_grades' => array_values(array_filter($scope->grades, static fn (GradeLevel $g) => DepEdSubjects::hasPreset($g) && !isset($used[$g->value]))),
            'bring_back_from' => [] !== $candidates ? $previous : null,
        ]);
    }

    protected function newSubject(Request $request, SubjectScope $scope): Subject
    {
        $grade = 1 === \count($scope->grades) ? $scope->grades[0] : GradeLevel::tryFrom($request->query->getInt('grade', -1));
        $subject = new Subject($scope->school, $scope->schoolYear);
        if ($scope->covers($grade)) {
            $subject->setGradeLevel($grade)->setPosition($this->subjects->nextPosition($scope->school, $scope->schoolYear, $grade));
            if ($grade->isSeniorHigh()) {
                $subject->setTrack($scope->defaultTrack)->setStrand($scope->defaultStrand);
            }
        }

        return $subject;
    }

    protected function handleForm(Request $request, SubjectScope $scope, Subject $subject): Response
    {
        if (null !== $refusal = $this->manager->refusal($scope->school)) {
            $this->addFlash('error', $refusal);

            return $this->redirectToList($scope);
        }

        $parents = array_values(array_filter(
            $this->subjects->findForSchool($scope->school, $scope->schoolYear),
            static fn (Subject $s) => !$s->isComponent() && $s !== $subject && $scope->covers($s->getGradeLevel()),
        ));
        $form = $this->createForm(SubjectFormType::class, $subject, ['grade_levels' => $scope->grades, 'parents' => $parents]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $isNew = null === $subject->getId();
            $this->manager->save($subject);
            $this->addFlash('success', \sprintf($isNew ? 'Created %s.' : 'Saved %s.', $subject->getLabel()));

            return $this->redirectToList($scope);
        }

        return $this->render('school_subjects/form.html.twig', $this->pageVars($scope) + [
            'form' => $form,
            'subject' => $subject,
            'strands' => SectionFormType::STRANDS,
        ]);
    }

    protected function handleDelete(SubjectScope $scope, Subject $subject): Response
    {
        try {
            $this->manager->delete($subject);
            $this->addFlash('success', \sprintf('Deleted %s.', $subject->getLabel()));
        } catch (PlanLimitException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToList($scope);
    }

    protected function handleDepEd(Request $request, SubjectScope $scope): Response
    {
        $grade = GradeLevel::tryFrom($request->request->getInt('grade', -1));
        if (!$scope->covers($grade) || !DepEdSubjects::hasPreset($grade)) {
            throw $this->createNotFoundException('There is no DepEd subject list for this grade level here.');
        }
        try {
            $count = $this->manager->addDepEdSet($scope->school, $scope->schoolYear, $grade);
            $this->addFlash('success', \sprintf('Added %d DepEd subjects for %s.', $count, $grade->label()));
        } catch (PlanLimitException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToList($scope);
    }

    protected function handleBringBack(SubjectScope $scope): Response
    {
        $previous = $this->schoolYears->findPrevious($scope->schoolYear) ?? throw $this->createNotFoundException('There is no previous school year.');
        try {
            $count = $this->manager->bringBack($scope->school, $previous, $scope->schoolYear, $scope->grades);
            $this->addFlash('success', \sprintf('Brought back %d subjects from S.Y. %s.', $count, $previous->getName()));
        } catch (PlanLimitException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToList($scope);
    }

    /** A subject this page manages: same school, current S.Y., a grade in scope. */
    protected function requireSubject(SubjectScope $scope, int $id): Subject
    {
        $subject = $this->subjects->find($id);
        if (null === $subject || $subject->getSchool() !== $scope->school || $subject->getSchoolYear() !== $scope->schoolYear || !$scope->covers($subject->getGradeLevel())) {
            throw $this->createNotFoundException('This subject is not offered here this school year.');
        }

        return $subject;
    }

    protected function redirectToList(SubjectScope $scope): Response
    {
        return $this->redirectToRoute($scope->routes, $scope->routeParams);
    }

    /** @return array<string, mixed> */
    private function pageVars(SubjectScope $scope): array
    {
        return [
            'school' => $scope->school,
            'school_year' => $scope->schoolYear,
            'layout' => $scope->layout,
            'routes' => $scope->routes,
            'route_params' => $scope->routeParams,
        ];
    }

    /** "Grade 7"; Senior High: "Grade 11 · 1st semester". */
    private static function groupLabel(Subject $subject): string
    {
        $label = $subject->getGradeLevel()->label();
        $semester = array_search($subject->getSemester(), SubjectFormType::SEMESTERS, true);

        return false !== $semester ? $label.' · '.$semester : $label;
    }
}
