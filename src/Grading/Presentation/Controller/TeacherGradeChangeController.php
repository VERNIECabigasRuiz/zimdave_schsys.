<?php

declare(strict_types=1);

namespace App\Grading\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Grading\Application\Query\Command\GetGradeChangeCommand;
use App\Grading\Application\Query\Command\GetGradeChangeFormCommand;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\SheetLearner;
use App\Grading\Application\UseCase\Command\RequestGradeChangeCommand;
use App\Grading\Application\UseCase\Command\WithdrawGradeChangeCommand;
use App\Grading\Domain\Model\GradeChangeRequest;
use App\Grading\Presentation\Form\GradeChange;
use App\Grading\Presentation\Form\GradeChangeFormType;
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
class TeacherGradeChangeController extends AbstractGradingController
{
    #[Route(
        '/{load}/{period}/change',
        name: 'teacher_grades_change',
        requirements: ['load' => '\d+', 'period' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function change(Request $request, #[CurrentUser] User $user, int $load, int $period): Response
    {
        $page = $this->ask(new GetGradeChangeFormCommand((int) $user->getId(), $load, $period));
        $sheet = ['load' => $load, 'period' => $period];
        if (\is_string($page['refusal'])) {
            $this->addFlash('error', $page['refusal']);

            return $this->redirectToRoute('teacher_grades_sheet', $sheet);
        }

        /** @var list<SheetLearner> $learners */
        $learners = $page['learners'];
        $form = $this->createForm(GradeChangeFormType::class, GradeChange::of($learners));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $change = $form->getData();
            $sending = new RequestGradeChangeCommand(
                (int) $user->getId(),
                $load,
                $period,
                self::id($page['school_id']),
                self::id($page['section_id']),
                self::id($page['subject_id']),
                $change->grades,
                $change->reason,
            );
            $refusal = $this->refusal($this->useCaseBus->handle($sending));
            if (null === $refusal) {
                $this->addFlash('success', 'Your change request was sent to the principal.');

                return $this->redirectToRoute('teacher_grades_sheet', $sheet);
            }
            $form->addError(new FormError($refusal));
        }

        return $this->render('@Grading/grades/change.html.twig', [
            'load' => $page['load'],
            'period' => $page['period'],
            'learners' => $learners,
            'saved' => $page['saved'],
            'form' => $form,
        ]);
    }

    #[Route(
        '/changes/{request}/withdraw',
        name: 'teacher_grades_change_withdraw',
        requirements: ['request' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(new Expression('"grade-change-withdraw-" ~ args["request"].getId()'), tokenKey: '_token')]
    public function withdraw(GradeChangeRequest $request, #[CurrentUser] User $user): Response
    {
        $requestId = (int) $request->getId();
        $page = $this->ask(new GetGradeChangeCommand(
            (int) $user->getId(),
            null,
            $requestId,
            GradePolicy::CHANGE_WITHDRAW,
        ));
        $withdrawing = new WithdrawGradeChangeCommand((int) $user->getId(), $requestId, self::id($page['school_id']));
        $refusal = $this->refusal($this->useCaseBus->handle($withdrawing));
        if (null === $refusal) {
            $this->addFlash('success', 'Your change request was withdrawn.');
        } else {
            $this->addFlash('error', $refusal);
        }

        // back to the class's sheet, unless it has moved to another teacher the requester may no longer view
        $sheet = $page['sheet'];

        return \is_array($sheet)
            ? $this->redirectToRoute('teacher_grades_sheet', $sheet)
            : $this->redirectToRoute('teacher_grades');
    }

    private static function id(mixed $id): int
    {
        \assert(\is_int($id));

        return $id;
    }
}
