<?php

declare(strict_types=1);

namespace App\Grading\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Grading\Application\Query\Command\GetGradeSheetCommand;
use App\Grading\Application\Query\Command\GetTeacherGradesCommand;
use App\Grading\Application\Service\SheetLearner;
use App\Grading\Application\UseCase\Command\SaveGradeSheetCommand;
use App\Grading\Application\UseCase\Handler\SaveGradeSheetHandler;
use App\Grading\Domain\Model\Grade;
use App\Grading\Presentation\Form\GradeSheet;
use App\Grading\Presentation\Form\GradeSheetFormType;
use App\Schools\Domain\Model\GradingPeriod;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A subject teacher's grades: the classes they grade this school year, and a class's grade sheet for a period. */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher/grades')]
class TeacherGradeController extends AbstractGradingController
{
    #[Route('', name: 'teacher_grades', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render(
            '@Grading/grades/index.html.twig',
            $this->ask(new GetTeacherGradesCommand((int) $user->getId())),
        );
    }

    #[Route(
        '/{load}/{period}',
        name: 'teacher_grades_sheet',
        requirements: ['load' => '\d+', 'period' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function sheet(Request $request, #[CurrentUser] User $user, int $load, int $period): Response
    {
        $page = $this->ask(new GetGradeSheetCommand((int) $user->getId(), $load, $period));
        $canEdit = true === $page['can_edit'];
        if ($request->isMethod('POST') && !$canEdit) {
            throw $this->createAccessDeniedException(SaveGradeSheetHandler::CANNOT_EDIT);
        }

        $form = null;
        // a locked or closed period renders read-only; a POST still goes to the use case, which refuses it
        if ($canEdit && ($request->isMethod('POST') || null === $page['refusal'])) {
            /** @var list<SheetLearner> $learners */
            $learners = $page['learners'];
            /** @var array<int, Grade> $saved */
            $saved = $page['saved'];
            $form = $this->createForm(GradeSheetFormType::class, GradeSheet::of($learners, $saved));
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $sheet = $form->getData();
                $saving = new SaveGradeSheetCommand(
                    (int) $user->getId(),
                    $load,
                    $period,
                    self::id($page['school_id']),
                    self::id($page['section_id']),
                    self::id($page['subject_id']),
                    $sheet->grades,
                );
                $result = $this->useCaseBus->handle($saving);
                $refusal = $this->refusal($result);
                if (null === $refusal) {
                    $this->addFlash('success', self::savedMessage($page['period'], $result->data()));

                    return $this->redirectToRoute('teacher_grades_sheet', ['load' => $load, 'period' => $period]);
                }
                $form->addError(new FormError($refusal));
            }
        }

        return $this->render('@Grading/grades/sheet.html.twig', $page + [
            'form' => $form,
            'editable' => null !== $form && null === $page['refusal'],
        ]);
    }

    private static function id(mixed $id): int
    {
        \assert(\is_int($id));

        return $id;
    }

    private static function savedMessage(mixed $period, mixed $count): string
    {
        \assert($period instanceof GradingPeriod && \is_int($count));

        return \sprintf(
            'Saved %s grades for %d %s.',
            $period->getLabel(),
            $count,
            1 === $count ? 'learner' : 'learners',
        );
    }
}
