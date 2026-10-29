<?php

namespace App\Controller;

use App\Attendance\AttendanceException;
use App\Attendance\AttendanceRecorder;
use App\Entity\School;
use App\Entity\User;
use App\Form\DayOffFormType;
use App\Repository\SchoolDayOffRepository;
use App\Repository\SchoolYearRepository;
use App\Security\Voter\SchoolVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The school calendar's days off this S.Y. — admin for any school, principal for their own. */
#[Route('/schools/{id}/calendar', requirements: ['id' => '\d+', 'dayOff' => '\d+'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolCalendarController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolDayOffRepository $daysOff,
        private readonly AttendanceRecorder $recorder,
    ) {
    }

    #[Route('', name: 'school_calendar', methods: ['GET', 'POST'])]
    public function index(Request $request, School $school, #[CurrentUser] User $user): Response
    {
        $schoolYear = $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');

        $form = $this->createForm(DayOffFormType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $result = $this->recorder->addDaysOff($school, $schoolYear, $form->get('from')->getData(), $form->get('to')->getData(), $form->get('reason')->getData(), $user);
                $this->addFlash('success', 0 === $result['added'] ? 'Those days are already off.' : \sprintf('%d %s off added.', $result['added'], 1 === $result['added'] ? 'day' : 'days'));
                if ($result['sectionsWithAttendance'] > 0) {
                    $this->addFlash('warning', \sprintf('%d %s already took attendance on those days; those days no longer count.', $result['sectionsWithAttendance'], 1 === $result['sectionsWithAttendance'] ? 'section' : 'sections'));
                }

                return $this->redirectToRoute('school_calendar', ['id' => $school->getId()]);
            } catch (AttendanceException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('attendance/calendar.html.twig', [
            'layout' => $this->layout(),
            'school' => $school,
            'school_year' => $schoolYear,
            'days_off' => $this->daysOff->findForYear($school, $schoolYear),
            'form' => $form,
        ]);
    }

    #[Route('/{dayOff}/remove', name: 'school_calendar_remove', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"day-off-" ~ args["dayOff"]'), tokenKey: '_token')]
    public function remove(School $school, int $dayOff): Response
    {
        $found = $this->daysOff->find($dayOff);
        if (null === $found || $found->getSchool()->getId() !== $school->getId()) {
            throw $this->createNotFoundException();
        }

        $this->recorder->removeDayOff($found);
        $this->addFlash('success', \sprintf('%s is a school day again.', $found->getDate()->format('l, F j')));

        return $this->redirectToRoute('school_calendar', ['id' => $school->getId()]);
    }

    private function layout(): string
    {
        return $this->isGranted(User::ROLE_ADMIN) ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig';
    }
}
