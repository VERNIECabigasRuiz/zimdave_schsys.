<?php

namespace App\Controller;

use App\Entity\GradeChangeRequest;
use App\Entity\GradingPeriod;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Form\GradeChange;
use App\Form\GradeChangeFormType;
use App\Form\GradeSheet;
use App\Form\GradeSheetFormType;
use App\Grading\ClassGradeStatus;
use App\Grading\GradeChangeManager;
use App\Grading\GradeRecorder;
use App\Grading\GradeRules;
use App\Grading\GradingException;
use App\Grading\SheetLearner;
use App\Repository\GradeChangeRequestRepository;
use App\Repository\GradeRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\TeachingLoadRepository;
use App\Security\Voter\GradeVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A subject teacher's grades: the classes they grade this school year, a class's grade sheet for a period, and their
 * change requests for the grades of a locked period.
 */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher/grades')]
class TeacherGradeController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly TeachingLoadRepository $loads,
        private readonly GradeRepository $grades,
        private readonly GradeRules $rules,
        private readonly GradeRecorder $recorder,
        private readonly EntityManagerInterface $entityManager,
        private readonly GradeChangeManager $changes,
        private readonly GradeChangeRequestRepository $changeRequests,
    ) {
    }

    #[Route('', name: 'teacher_grades', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $loads = null === $schoolYear ? [] : $this->loads->findForTeacher($user, $schoolYear);
        usort($loads, static fn (TeachingLoad $a, TeachingLoad $b) => self::sortKey($a) <=> self::sortKey($b));

        $periods = null === $schoolYear ? [] : $schoolYear->getGradingPeriods()->getValues();
        $graded = [];
        $ungraded = [];
        foreach ($loads as $load) {
            if (!$this->rules->isGradable($load)) {
                $ungraded[] = $load;
                continue;
            }
            $editable = array_values(array_filter($this->rules->sheet($load->getSection()), static fn (SheetLearner $l) => $l->editable));
            $ids = GradeRules::enrollmentIds($editable);
            $cells = [];
            foreach ($periods as $period) {
                $saved = $this->grades->findForEnrollments($ids, $load->getSubject(), $period);
                $cells[] = [
                    'period' => $period,
                    'graded' => \count(array_filter($editable, static fn (SheetLearner $l) => isset($saved[$l->enrollment->getId()]))),
                    'listed' => \count($editable),
                    'status' => $this->rules->status($load->getSection(), $load->getSubject(), $period),
                ];
            }
            $graded[] = ['load' => $load, 'cells' => $cells];
        }

        return $this->render('grades/index.html.twig', [
            'school_year' => $schoolYear,
            'periods' => $periods,
            'graded' => $graded,
            'ungraded' => $ungraded,
        ]);
    }

    #[Route('/{load}/{period}', name: 'teacher_grades_sheet', requirements: ['load' => '\d+', 'period' => '\d+'], methods: ['GET', 'POST'])]
    public function sheet(Request $request, #[CurrentUser] User $user, int $load, int $period): Response
    {
        [$load, $period] = $this->findClass($load, $period);
        $this->denyAccessUnlessGranted(GradeVoter::VIEW, $load);
        $canEdit = $this->isGranted(GradeVoter::EDIT, $load);
        if ($request->isMethod('POST') && !$canEdit) {
            throw $this->createAccessDeniedException('You cannot enter grades for this class.');
        }

        $section = $load->getSection();
        $subject = $load->getSubject();
        $learners = $this->rules->sheet($section);
        // by enrollment: a learner who transferred out has moved section but keeps the grades given here
        $saved = $this->grades->findForEnrollments(GradeRules::enrollmentIds($learners), $subject, $period);
        $gradable = $this->rules->isGradable($load);
        $status = $gradable ? $this->rules->status($section, $subject, $period) : null;
        $refusal = null === $status ? "This class isn't graded here." : $this->rules->refusalToEdit($status, $period);

        $form = null;
        // a locked or closed period renders read-only; a POST still goes to the recorder, which refuses it
        if ($canEdit && ($request->isMethod('POST') || null === $refusal)) {
            $form = $this->createForm(GradeSheetFormType::class, GradeSheet::of($learners, $saved));
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var GradeSheet $sheet */
                $sheet = $form->getData();
                try {
                    $count = $this->recorder->saveSheet($load, $period, $sheet->grades, $user);
                    $this->addFlash('success', \sprintf('Saved %s grades for %d %s.', $period->getLabel(), $count, 1 === $count ? 'learner' : 'learners'));

                    return $this->redirectToRoute('teacher_grades_sheet', ['load' => $load->getId(), 'period' => $period->getId()]);
                } catch (GradingException $e) {
                    $form->addError(new FormError($e->getMessage()));
                }
            }
        }

        $changeRequests = $this->changeRequests->findForClass($section, $subject, $period);
        $waiting = array_filter($changeRequests, static fn (GradeChangeRequest $r): bool => $r->isPending());
        $pending = [] !== $waiting;
        // "your" request only for the teacher who sent it, not the adviser or a teacher who took over the class
        $ownPending = [] !== array_filter($waiting, static fn (GradeChangeRequest $r): bool => $r->getRequestedBy()->getId() === $user->getId());

        return $this->render('grades/sheet.html.twig', [
            'load' => $load,
            'period' => $period,
            'periods' => $section->getSchoolYear()->getGradingPeriods(),
            'learners' => $learners,
            'saved' => $saved,
            'status' => $status,
            'refusal' => $refusal,
            'form' => $form,
            'editable' => null !== $form && null === $refusal,
            'change_requests' => $changeRequests,
            'pending' => $pending,
            'own_pending' => $ownPending,
            'can_request' => $canEdit && ClassGradeStatus::Locked === $status && !$pending,
        ]);
    }

    #[Route('/{load}/{period}/change', name: 'teacher_grades_change', requirements: ['load' => '\d+', 'period' => '\d+'], methods: ['GET', 'POST'])]
    public function change(Request $request, #[CurrentUser] User $user, int $load, int $period): Response
    {
        [$load, $period] = $this->findClass($load, $period);
        $this->denyAccessUnlessGranted(GradeVoter::EDIT, $load);
        $sheet = ['load' => $load->getId(), 'period' => $period->getId()];

        $refusal = $this->refusalToRequest($load, $period);
        if (null !== $refusal) {
            $this->addFlash('error', $refusal);

            return $this->redirectToRoute('teacher_grades_sheet', $sheet);
        }

        $learners = $this->rules->sheet($load->getSection());
        $saved = $this->grades->findForEnrollments(GradeRules::enrollmentIds($learners), $load->getSubject(), $period);
        $form = $this->createForm(GradeChangeFormType::class, GradeChange::of($learners));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var GradeChange $change */
            $change = $form->getData();
            try {
                $this->changes->request($load, $period, $change->grades, $change->reason, $user);
                $this->addFlash('success', 'Your change request was sent to the principal.');

                return $this->redirectToRoute('teacher_grades_sheet', $sheet);
            } catch (GradingException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('grades/change.html.twig', [
            'load' => $load,
            'period' => $period,
            'learners' => $learners,
            'saved' => $saved,
            'form' => $form,
        ]);
    }

    #[Route('/changes/{request}/withdraw', name: 'teacher_grades_change_withdraw', requirements: ['request' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"grade-change-withdraw-" ~ args["request"].getId()'), tokenKey: '_token')]
    public function withdraw(GradeChangeRequest $request, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(GradeVoter::CHANGE_WITHDRAW, $request);
        try {
            $this->changes->withdraw($request, $user);
            $this->addFlash('success', 'Your change request was withdrawn.');
        } catch (GradingException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        // back to the class's sheet, unless it has moved to another teacher the requester may no longer view
        $load = $this->loads->findOne($request->getSection(), $request->getSubject());

        return null !== $load && $this->isGranted(GradeVoter::VIEW, $load)
            ? $this->redirectToRoute('teacher_grades_sheet', ['load' => $load->getId(), 'period' => $request->getGradingPeriod()->getId()])
            : $this->redirectToRoute('teacher_grades');
    }

    /** @return array{TeachingLoad, GradingPeriod} */
    private function findClass(int $loadId, int $periodId): array
    {
        $load = $this->loads->find($loadId);
        $period = $this->entityManager->find(GradingPeriod::class, $periodId);
        if (null === $load || null === $period || $period->getSchoolYear() !== $load->getSection()->getSchoolYear()) {
            throw $this->createNotFoundException('No such class or grading period.');
        }

        return [$load, $period];
    }

    /** Why a change request can't be sent for the class now, as the manager would refuse it; null when it can. */
    private function refusalToRequest(TeachingLoad $load, GradingPeriod $period): ?string
    {
        $section = $load->getSection();
        $subject = $load->getSubject();
        $status = $this->rules->status($section, $subject, $period);
        if (ClassGradeStatus::Open === $status || ClassGradeStatus::Unlocked === $status) {
            return \sprintf("%s isn't locked — change the grades on the sheet.", $period->getLabel());
        }
        if (ClassGradeStatus::Locked !== $status) {
            return $this->rules->refusalToEdit($status, $period) ?? \sprintf("%s isn't open for grading.", $period->getLabel());
        }
        if (null !== $this->changeRequests->findPendingFor($section, $subject, $period)) {
            return 'You already have a pending change request for this class.';
        }

        return null;
    }

    /** @return array{int, string, int, string} grade level, section, subject position, subject */
    private static function sortKey(TeachingLoad $load): array
    {
        $section = $load->getSection();

        return [$section->getGradeLevel()?->value ?? -1, mb_strtoupper($section->getName()), $load->getSubject()->getPosition() ?? \PHP_INT_MAX, mb_strtoupper($load->getSubject()->getName())];
    }
}
