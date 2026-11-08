<?php

declare(strict_types=1);

namespace App\Attendance\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Attendance\Application\Query\Command\GetRollCallPageCommand;
use App\Attendance\Application\UseCase\Command\SubmitSf2Command;
use App\Attendance\Application\UseCase\Command\TakeRollCallCommand;
use App\Attendance\Domain\Model\LearnerMark;
use App\Attendance\Presentation\Form\RollCallFormType;
use App\Attendance\Presentation\Form\Sf2SubmitFormType;
use App\Classes\Domain\Model\Section;
use App\Learners\Application\Api\RosterEntry;
use App\Attendance\Domain\Model\RollCall;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The adviser's attendance: the day's roll call, the month grid and SF2. The advisory is
 * looked up on every request, as on the class pages.
 */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher/class/attendance', requirements: ['date' => '\d{4}-\d{2}-\d{2}', 'month' => '\d{4}-\d{2}'])]
class TeacherAttendanceController extends AbstractAttendanceController
{
    #[Route('/{date}', name: 'teacher_class_attendance', methods: ['GET', 'POST'])]
    public function rollCall(Request $request, #[CurrentUser] User $user, ?string $date = null): Response
    {
        $day = null === $date
            ? null
            : (\DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: throw $this->createNotFoundException());
        /** @var array{section: Section, day: \DateTimeImmutable, today: \DateTimeImmutable, refusal: ?string,
         *     entries: list<RosterEntry>, counted: list<RosterEntry>, rollCall: ?RollCall, counts: array<string, int>,
         *     previousDay: \DateTimeImmutable, nextDay: ?\DateTimeImmutable} $page */
        $page = $this->ask(new GetRollCallPageCommand((int) $user->getId(), $day));
        $day = $page['day'];

        $form = null;
        if (null === $page['refusal']) {
            $data = [];
            foreach ($page['counted'] as $entry) {
                $data[RollCallFormType::field($entry)] = $page['rollCall']?->markFor($entry->enrollment);
            }
            $form = $this->createForm(RollCallFormType::class, $data, ['entries' => $page['counted']]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $marks = [];
                foreach ($page['counted'] as $entry) {
                    $mark = $form->get(RollCallFormType::field($entry))->getData();
                    \assert(null === $mark || $mark instanceof LearnerMark);
                    $marks[(int) $entry->enrollment->getId()] = $mark;
                }
                $taken = $this->useCaseBus->handle(
                    new TakeRollCallCommand((int) $user->getId(), (int) $page['section']->getId(), $day, $marks),
                );
                if (null !== $refusal = $this->refusal($taken)) {
                    $this->addFlash('error', $refusal);
                } else {
                    $this->addFlash('success', \sprintf('Attendance saved for %s.', $day->format('l, F j')));
                }

                return $this->redirectToRoute('teacher_class_attendance', ['date' => $day->format('Y-m-d')]);
            }
        }

        return $this->render('@Attendance/attendance/roll_call.html.twig', [
            'layout' => 'teacher/_layout.html.twig',
            'section' => $page['section'],
            'day' => $day,
            'today' => $page['today'],
            'refusal' => $page['refusal'],
            'form' => $form?->createView(),
            'entries' => $page['entries'],
            'roll_call' => $page['rollCall'],
            'counts' => $page['counts'],
            'previous_day' => $page['previousDay'],
            'next_day' => $page['nextDay'],
        ]);
    }

    #[Route('/month/{month}', name: 'teacher_class_attendance_month', methods: ['GET'])]
    public function month(#[CurrentUser] User $user, ?string $month = null): Response
    {
        $page = $this->monthPage((int) $user->getId(), null, null, $this->monthOf($month));
        $submitForm = null;
        if (true === $page['canSubmit']) {
            $shown = $page['month'];
            \assert($shown instanceof \DateTimeImmutable);
            $submitForm = $this->submitForm($shown, self::missingDays($page))->createView();
        }

        return $this->renderMonth($page, $this->routes(), ['submit_form' => $submitForm]);
    }

    #[Route('/month/{month}/sf2', name: 'teacher_class_sf2', methods: ['GET'])]
    public function sf2(#[CurrentUser] User $user, string $month): Response
    {
        $first = $this->monthOf($month);
        \assert(null !== $first);

        return $this->downloadSf2((int) $user->getId(), null, null, $first);
    }

    #[Route('/month/{month}/submit', name: 'teacher_class_attendance_submit', methods: ['POST'])]
    public function submit(Request $request, #[CurrentUser] User $user, string $month): Response
    {
        $first = $this->monthOf($month);
        \assert(null !== $first);
        $page = $this->monthPage((int) $user->getId(), null, null, $first);
        $section = $page['section'];
        \assert($section instanceof Section);

        $form = $this->submitForm($first, self::missingDays($page));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $confirmMissing = $form->has('confirmMissing') && true === $form->get('confirmMissing')->getData();
            $submitted = $this->useCaseBus->handle(
                new SubmitSf2Command((int) $user->getId(), (int) $section->getId(), $first, $confirmMissing),
            );
            if (null !== $refusal = $this->refusal($submitted)) {
                $this->addFlash('error', $refusal);
            } else {
                $this->addFlash(
                    'success',
                    \sprintf('SF2 for %s submitted to the principal. The month is now locked.', $first->format('F Y')),
                );
            }
        }

        return $this->redirectToRoute('teacher_class_attendance_month', ['month' => $first->format('Y-m')]);
    }

    /** @param array<string, mixed> $page the month query's data */
    private static function missingDays(array $page): int
    {
        $missing = $page['missingDays'];
        \assert(\is_int($missing));

        return $missing;
    }

    /** @return FormInterface<mixed> */
    private function submitForm(\DateTimeImmutable $month, int $missingDays): FormInterface
    {
        return $this->createForm(Sf2SubmitFormType::class, null, [
            'missing_days' => $missingDays,
            'action' => $this->generateUrl('teacher_class_attendance_submit', ['month' => $month->format('Y-m')]),
        ]);
    }

    /**
     * @return array{layout: string, month_route: string, route_params: array<string, mixed>, sf2_route: string,
     *     day_route: ?string}
     */
    private function routes(): array
    {
        return [
            'layout' => 'teacher/_layout.html.twig',
            'month_route' => 'teacher_class_attendance_month',
            'route_params' => [],
            'sf2_route' => 'teacher_class_sf2',
            'day_route' => 'teacher_class_attendance',
        ];
    }
}
