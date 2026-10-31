<?php

namespace App\Controller;

use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use App\Form\GradeLockFormType;
use App\Grading\ClassProgress;
use App\Grading\GradeRecorder;
use App\Grading\GradeRules;
use App\Grading\GradingException;
use App\Grading\SchoolGradeOverview;
use App\Grading\SectionGradeGrid;
use App\Repository\GradeChangeRequestRepository;
use App\Repository\GradeLockRepository;
use App\Repository\GradeOpeningRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\SectionRepository;
use App\Repository\SubjectRepository;
use App\Security\Voter\GradeVoter;
use App\Security\Voter\SchoolVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The school's grades — admin for any school, principal for their own: every class's progress for a
 * grading period, and each section's grades, read-only. Only the principal locks and unlocks.
 */
#[Route('/schools/{id}', requirements: ['id' => '\d+', 'section' => '\d+', 'subject' => '\d+', 'period' => '\d+'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolGradeController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SectionRepository $sections,
        private readonly SubjectRepository $subjects,
        private readonly GradeLockRepository $locks,
        private readonly GradeOpeningRepository $openings,
        private readonly GradeRules $rules,
        private readonly GradeRecorder $recorder,
        private readonly SectionGradeGrid $grid,
    ) {
    }

    #[Route('/grades', name: 'school_grades', methods: ['GET'])]
    public function overview(School $school, SchoolGradeOverview $overview, GradeChangeRequestRepository $changeRequests, #[MapQueryParameter] ?int $period = null): Response
    {
        $schoolYear = $this->currentYear();
        $selected = $this->selectPeriod($school, $schoolYear, $period);
        $classes = null === $selected ? [] : $overview->build($school, $schoolYear, $selected);
        $lock = null === $selected ? null : $this->locks->findFor($school, $selected);
        $canLock = $this->isGranted(GradeVoter::LOCK, $school);

        return $this->render('grades/school.html.twig', [
            'layout' => $this->layout(),
            'school' => $school,
            'school_year' => $schoolYear,
            'periods' => $schoolYear->getGradingPeriods(),
            'period' => $selected,
            'lock' => $lock,
            'opening' => null === $selected ? null : $this->openings->findFor($school, $selected),
            'classes' => $classes,
            'complete' => \count(array_filter($classes, static fn (ClassProgress $c) => $c->isComplete())),
            'can_lock' => $canLock,
            'can_open' => $this->isGranted(GradeVoter::OPEN, $school),
            'lock_form' => $canLock && null !== $selected && null === $lock ? $this->lockForm($school, $selected)->createView() : null,
            'pending_changes' => $changeRequests->countPendingForSchool($school, $schoolYear),
        ]);
    }

    #[Route('/sections/{section}/grades', name: 'school_grades_section', methods: ['GET'])]
    public function section(School $school, int $section, #[MapQueryParameter] ?int $period = null): Response
    {
        $section = $this->requireSection($school, $section);
        $this->denyAccessUnlessGranted(GradeVoter::VIEW_SECTION, $section);
        $schoolYear = $section->getSchoolYear();
        $selected = $this->selectPeriod($school, $schoolYear, $period);

        return $this->render('grades/school_section.html.twig', [
            'layout' => $this->layout(),
            'school' => $school,
            'section' => $section,
            'periods' => $schoolYear->getGradingPeriods(),
            'period' => $selected,
            'grid' => null === $selected ? null : $this->grid->build($section, $selected),
        ]);
    }

    #[Route('/grades/{period}/open', name: 'school_grades_open', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"grade-open-" ~ args["period"]'), tokenKey: '_token')]
    public function open(School $school, int $period, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(GradeVoter::OPEN, $school);
        $period = $this->requirePeriod($school, $period);

        try {
            $this->recorder->openPeriod($school, $period, $user);
            $this->addFlash('success', \sprintf('%s is open for grading.', $period->getLabel()));
        } catch (GradingException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->backTo($school, $period);
    }

    #[Route('/grades/{period}/close', name: 'school_grades_close', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"grade-close-" ~ args["period"]'), tokenKey: '_token')]
    public function close(School $school, int $period, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(GradeVoter::OPEN, $school);
        $period = $this->requirePeriod($school, $period);

        try {
            $this->recorder->closePeriod($school, $period, $user);
            $this->addFlash('success', \sprintf('%s grading is closed.', $period->getLabel()));
        } catch (GradingException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->backTo($school, $period);
    }

    #[Route('/grades/{period}/lock', name: 'school_grades_lock', methods: ['POST'])]
    public function lock(Request $request, School $school, int $period, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(GradeVoter::LOCK, $school);
        $period = $this->requirePeriod($school, $period);

        $form = $this->lockForm($school, $period)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->recorder->lockPeriod($school, $period, $user);
                $this->addFlash('success', \sprintf('%s grades are locked.', $period->getLabel()));
            } catch (GradingException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        } else {
            $errors = $form->getErrors(true);
            $this->addFlash('error', \count($errors) > 0 ? $errors[0]->getMessage() : \sprintf('Tick "Yes, lock every class in %s" to lock it.', $period->getLabel()));
        }

        return $this->backTo($school, $period);
    }

    #[Route('/grades/{period}/unlock', name: 'school_grades_unlock', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"grade-unlock-" ~ args["period"]'), tokenKey: '_token')]
    public function unlock(School $school, int $period): Response
    {
        $this->denyAccessUnlessGranted(GradeVoter::LOCK, $school);
        $period = $this->requirePeriod($school, $period);

        try {
            $this->recorder->unlockPeriod($school, $period);
            $this->addFlash('success', \sprintf('%s grades are unlocked.', $period->getLabel()));
        } catch (GradingException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->backTo($school, $period);
    }

    #[Route('/sections/{section}/subjects/{subject}/grades/{period}/unlock', name: 'school_grades_unlock_class', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"grade-unlock-class-" ~ args["section"] ~ "-" ~ args["subject"] ~ "-" ~ args["period"]'), tokenKey: '_token')]
    public function unlockClass(School $school, int $section, int $subject, int $period, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(GradeVoter::LOCK, $school);
        [$section, $subject] = $this->requireClass($school, $section, $subject);
        $period = $this->requirePeriod($school, $period);

        try {
            $this->recorder->unlockClass($section, $subject, $period, $user);
            $this->addFlash('success', \sprintf('%s is unlocked for corrections.', GradeRecorder::classLabel($section, $subject)));
        } catch (GradingException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->backTo($school, $period);
    }

    #[Route('/sections/{section}/subjects/{subject}/grades/{period}/lock', name: 'school_grades_lock_class', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"grade-lock-class-" ~ args["section"] ~ "-" ~ args["subject"] ~ "-" ~ args["period"]'), tokenKey: '_token')]
    public function lockClass(School $school, int $section, int $subject, int $period): Response
    {
        $this->denyAccessUnlessGranted(GradeVoter::LOCK, $school);
        [$section, $subject] = $this->requireClass($school, $section, $subject);
        $period = $this->requirePeriod($school, $period);

        try {
            $this->recorder->lockClass($section, $subject, $period);
            $this->addFlash('success', \sprintf('%s is locked again.', GradeRecorder::classLabel($section, $subject)));
        } catch (GradingException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->backTo($school, $period);
    }

    private function lockForm(School $school, GradingPeriod $period): FormInterface
    {
        return $this->createForm(GradeLockFormType::class, null, [
            'period_label' => $period->getLabel(),
            'action' => $this->generateUrl('school_grades_lock', ['id' => $school->getId(), 'period' => $period->getId()]),
        ]);
    }

    private function currentYear(): SchoolYear
    {
        return $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');
    }

    /** The asked-for period of the school year (404 if it isn't one), or the one the grid opens on. */
    private function selectPeriod(School $school, SchoolYear $schoolYear, ?int $id): ?GradingPeriod
    {
        if (null === $id) {
            return $this->grid->defaultPeriod($school, $schoolYear);
        }

        return $schoolYear->getGradingPeriods()->findFirst(static fn (int $i, GradingPeriod $p) => $p->getId() === $id)
            ?? throw $this->createNotFoundException('No such grading period.');
    }

    /** A grading period of the current school year; only those can be locked. */
    private function requirePeriod(School $school, int $id): GradingPeriod
    {
        return $this->selectPeriod($school, $this->currentYear(), $id) ?? throw $this->createNotFoundException();
    }

    private function requireSection(School $school, int $id): Section
    {
        $section = $this->sections->find($id);
        if (null === $section || $section->getSchool()->getId() !== $school->getId()) {
            throw $this->createNotFoundException();
        }

        return $section;
    }

    /** @return array{Section, Subject} a gradable class of the school: a subject the section takes */
    private function requireClass(School $school, int $sectionId, int $subjectId): array
    {
        $section = $this->requireSection($school, $sectionId);
        $subject = $this->subjects->find($subjectId);
        if (null === $subject || !\in_array($subject, $this->subjects->findForSection($section), true) || !$this->rules->isGradableSubject($section, $subject)) {
            throw $this->createNotFoundException();
        }

        return [$section, $subject];
    }

    private function backTo(School $school, GradingPeriod $period): Response
    {
        return $this->redirectToRoute('school_grades', ['id' => $school->getId(), 'period' => $period->getId()]);
    }

    private function layout(): string
    {
        return $this->isGranted(User::ROLE_ADMIN) ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig';
    }
}
