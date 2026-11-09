<?php

declare(strict_types=1);

namespace App\Grading\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Grading\Application\Query\Command\GetCardSlipPdfCommand;
use App\Grading\Application\Query\Command\GetCardSlipsCommand;
use App\Grading\Application\UseCase\Command\ResetCardSlipCommentCommand;
use App\Grading\Application\UseCase\Command\SaveCardSlipCommentsCommand;
use App\Grading\Domain\Service\CardSlip\CardSlip;
use App\Grading\Presentation\Form\CardSlipCommentsData;
use App\Grading\Presentation\Form\CardSlipCommentsFormType;
use App\Schools\Domain\Model\GradingPeriod;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The adviser's card slips: comments for each learner of their class, and the slips as PDF. */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher/class/card-slips', requirements: ['period' => '\d+', 'enrollment' => '\d+'])]
class TeacherCardSlipController extends AbstractGradingController
{
    public const NO_LEARNERS = 'No learners to print slips for.';

    #[Route('', name: 'teacher_class_card_slips', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?int $period = null,
    ): Response {
        $page = $this->ask(new GetCardSlipsCommand((int) $user->getId(), $period));
        /** @var list<CardSlip> $slips */
        $slips = $page['slips'];
        $selected = $page['period'];
        $form = null;
        if ([] !== $slips && $selected instanceof GradingPeriod) {
            $names = [];
            $suggested = [];
            foreach ($slips as $slip) {
                $names[$slip->enrollmentId] = $slip->learnerName;
                $suggested[$slip->enrollmentId] = $slip->suggestedCodes;
            }
            $form = $this->createForm(
                CardSlipCommentsFormType::class,
                CardSlipCommentsData::of($slips),
                ['names' => $names],
            );
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                $result = $this->useCaseBus->handle(new SaveCardSlipCommentsCommand(
                    (int) $user->getId(),
                    (int) $selected->getId(),
                    $data->entries,
                    $suggested,
                ));
                $this->refusal($result);
                $count = $result->data();
                \assert(\is_int($count));
                $this->addFlash(
                    'success',
                    \sprintf('Comments saved for %d %s.', $count, 1 === $count ? 'learner' : 'learners'),
                );

                return $this->redirectToRoute('teacher_class_card_slips', ['period' => $selected->getId()]);
            }
        }

        return $this->render('@Grading/card_slip/index.html.twig', [
            'section' => $page['section'],
            'periods' => $page['periods'],
            'period' => $selected,
            'locked' => $page['locked'],
            'rows' => $page['rows'],
            'groups' => $page['groups'],
            'form' => $form,
        ]);
    }

    #[Route('/{period}/{enrollment}/reset', name: 'teacher_class_card_slip_reset', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"card-slip-reset-" ~ args["enrollment"]'), tokenKey: '_token')]
    public function reset(#[CurrentUser] User $user, int $period, int $enrollment): Response
    {
        $result = $this->useCaseBus->handle(
            new ResetCardSlipCommentCommand((int) $user->getId(), $period, $enrollment),
        );
        $this->refusal($result);
        $name = $result->data();
        \assert(\is_string($name));
        $this->addFlash('success', \sprintf('%s’s comments are back to the suggestions.', $name));

        return $this->redirectToRoute('teacher_class_card_slips', ['period' => $period]);
    }

    #[Route('/{period}.pdf', name: 'teacher_class_card_slips_pdf', methods: ['GET'])]
    public function pdfAll(#[CurrentUser] User $user, int $period): Response
    {
        $file = $this->ask(new GetCardSlipPdfCommand((int) $user->getId(), null, null, $period, null));
        if (!\is_string($file['content'])) {
            $this->addFlash('error', self::NO_LEARNERS);

            return $this->redirectToRoute('teacher_class_card_slips', ['period' => $period]);
        }

        return $this->download($file);
    }

    #[Route('/{period}/{enrollment}.pdf', name: 'teacher_class_card_slip_pdf', methods: ['GET'])]
    public function pdfOne(#[CurrentUser] User $user, int $period, int $enrollment): Response
    {
        $file = $this->ask(new GetCardSlipPdfCommand((int) $user->getId(), null, null, $period, $enrollment));
        return $this->download($file);
    }
}
