<?php

namespace App\Controller;

use App\Attendance\AttendanceException;
use App\Attendance\RosterEntry;
use App\Entity\LearnerMark;
use App\Entity\Section;
use App\Entity\User;
use App\Form\RollCallFormType;
use App\Form\Sf2SubmitFormType;
use App\Security\Voter\AttendanceVoter;
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
        $section = $this->advisory($user);
        $day = null === $date ? $this->rules->today() : (\DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: throw $this->createNotFoundException());

        $refusal = $this->isGranted(AttendanceVoter::TAKE, $section)
            ? $this->rules->refusalToTake($section, $day)
            : 'You can no longer take attendance for this section.';
        $entries = $this->roster->forMonth($section, $day);
        $counted = array_values(array_filter($entries, static fn (RosterEntry $e) => $e->counts($day)));
        $rollCall = $this->rollCalls->findOne($section, $day);

        $form = null;
        if (null === $refusal) {
            $data = [];
            foreach ($counted as $entry) {
                $data[RollCallFormType::field($entry)] = $rollCall?->markFor($entry->enrollment);
            }
            $form = $this->createForm(RollCallFormType::class, $data, ['entries' => $counted]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $marks = [];
                foreach ($counted as $entry) {
                    $marks[(int) $entry->enrollment->getId()] = $form->get(RollCallFormType::field($entry))->getData();
                }
                try {
                    $this->recorder->takeRollCall($section, $day, $marks, $user);
                    $this->addFlash('success', \sprintf('Attendance saved for %s.', $day->format('l, F j')));
                } catch (AttendanceException $e) {
                    $this->addFlash('error', $e->getMessage());
                }

                return $this->redirectToRoute('teacher_class_attendance', ['date' => $day->format('Y-m-d')]);
            }
        }

        $counts = ['present' => \count($counted)] + array_fill_keys(array_map(static fn (LearnerMark $m) => $m->value, LearnerMark::cases()), 0);
        foreach ($counted as $entry) {
            if (null !== $mark = $rollCall?->markFor($entry->enrollment)) {
                ++$counts[$mark->value];
                $counts['present'] -= LearnerMark::Absent === $mark ? 1 : 0;
            }
        }

        return $this->render('attendance/roll_call.html.twig', [
            'layout' => 'teacher/_layout.html.twig',
            'section' => $section,
            'day' => $day,
            'today' => $this->rules->today(),
            'refusal' => $refusal,
            'form' => $form?->createView(),
            'entries' => $entries,
            'roll_call' => $rollCall,
            'counts' => $counts,
            'previous_day' => self::weekday($day, -1),
            'next_day' => self::weekday($day, 1) <= $this->rules->today() ? self::weekday($day, 1) : null,
        ]);
    }

    #[Route('/month/{month}', name: 'teacher_class_attendance_month', methods: ['GET'])]
    public function month(#[CurrentUser] User $user, ?string $month = null): Response
    {
        $section = $this->advisory($user);
        $month = $this->monthOf($month);
        $submitForm = null;
        if ($this->isGranted(AttendanceVoter::TAKE, $section) && !$this->rules->isLocked($section, $month) && $this->rules->monthHasEnded($section, $month)) {
            $submitForm = $this->submitForm($section, $month)->createView();
        }

        return $this->renderMonth($section, $month, $this->routes(), ['submit_form' => $submitForm]);
    }

    #[Route('/month/{month}/sf2', name: 'teacher_class_sf2', methods: ['GET'])]
    public function sf2(#[CurrentUser] User $user, string $month): Response
    {
        return $this->downloadSf2($this->advisory($user), $this->monthOf($month));
    }

    #[Route('/month/{month}/submit', name: 'teacher_class_attendance_submit', methods: ['POST'])]
    public function submit(Request $request, #[CurrentUser] User $user, string $month): Response
    {
        $section = $this->advisory($user);
        $this->denyAccessUnlessGranted(AttendanceVoter::TAKE, $section);
        $month = $this->monthOf($month);

        $form = $this->submitForm($section, $month);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->recorder->submit($section, $month, $user, $form->has('confirmMissing') && true === $form->get('confirmMissing')->getData());
                $this->addFlash('success', \sprintf('SF2 for %s submitted to the principal. The month is now locked.', $month->format('F Y')));
            } catch (AttendanceException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('teacher_class_attendance_month', ['month' => $month->format('Y-m')]);
    }

    private function submitForm(Section $section, \DateTimeImmutable $month): FormInterface
    {
        return $this->createForm(Sf2SubmitFormType::class, null, [
            'missing_days' => \count($this->rules->missingDays($section, $month)),
            'action' => $this->generateUrl('teacher_class_attendance_submit', ['month' => $month->format('Y-m')]),
        ]);
    }

    /** @return array{layout: string, month_route: string, route_params: array<string, mixed>, sf2_route: string, day_route: ?string} */
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

    private function advisory(User $user): Section
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $section = null === $schoolYear ? null : $this->sections->findAdvisory($user, $schoolYear);
        if (null === $section) {
            throw $this->createAccessDeniedException('You do not advise a section this school year.');
        }
        $this->denyAccessUnlessGranted(AttendanceVoter::VIEW, $section);

        return $section;
    }

    /** The weekday $step days away (skipping weekends). */
    private static function weekday(\DateTimeImmutable $day, int $step): \DateTimeImmutable
    {
        do {
            $day = $day->modify(($step > 0 ? '+' : '-').'1 day');
        } while ((int) $day->format('N') > 5);

        return $day;
    }
}
