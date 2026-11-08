<?php

namespace App\Controller;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Repository\TeachingLoadRepositoryInterface;
use App\Classes\Domain\Model\TeachingLoad;
use App\Form\GradeChange;
use App\Form\GradeChangeFormType;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradeRules;
use App\Grading\Domain\Model\ClassGradeStatus;
use App\Grading\Domain\Model\GradeChangeRequest;
use App\Grading\Domain\Model\Repository\GradeChangeRequestRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;
use App\Grading\GradeChangeManager;
use App\Grading\GradingException;
use App\Schools\Domain\Model\GradingPeriod;
use App\Security\Voter\GradeVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A subject teacher's change requests for the grades of a locked period. */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher/grades')]
class TeacherGradeChangeController extends AbstractController
{
    public function __construct(
        private readonly TeachingLoadRepositoryInterface $loads,
        private readonly GradeRepositoryInterface $grades,
        private readonly GradeRules $rules,
        private readonly GradePolicy $policy,
        private readonly GradeChangeManager $changes,
        private readonly GradeChangeRequestRepositoryInterface $changeRequests,
    ) {
    }

    #[Route('/{load}/{period}/change', name: 'teacher_grades_change', requirements: ['load' => '\d+', 'period' => '\d+'], methods: ['GET', 'POST'])]
    public function change(Request $request, #[CurrentUser] User $user, int $load, int $period): Response
    {
        [$load, $period] = $this->findClass($load, $period);
        if (!$this->policy->can($user, GradePolicy::EDIT, $load)) {
            throw $this->createAccessDeniedException();
        }
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

        return null !== $load && $this->policy->can($user, GradePolicy::VIEW, $load)
            ? $this->redirectToRoute('teacher_grades_sheet', ['load' => $load->getId(), 'period' => $request->getGradingPeriod()->getId()])
            : $this->redirectToRoute('teacher_grades');
    }

    /** @return array{TeachingLoad, GradingPeriod} */
    private function findClass(int $loadId, int $periodId): array
    {
        $load = $this->loads->findById($loadId);
        $period = $load?->getSection()->getSchoolYear()->getGradingPeriods()
            ->findFirst(static fn (int $i, GradingPeriod $p) => $p->getId() === $periodId);
        if (null === $load || null === $period) {
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
}
