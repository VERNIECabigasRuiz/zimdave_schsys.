<?php

namespace App\Controller;

use App\Attendance\AttendanceException;
use App\Attendance\AttendanceStatistics;
use App\Entity\School;
use App\Entity\Section;
use App\Entity\User;
use App\Security\Voter\AttendanceVoter;
use App\Security\Voter\SchoolVoter;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The school's attendance — admin for any school, principal for their own: an overview with
 * statistics, and each section's month, read-only, with its SF2. Only advisers take attendance.
 */
#[Route('/schools/{id}', requirements: ['id' => '\d+', 'section' => '\d+', 'month' => '\d{4}-\d{2}'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolAttendanceController extends AbstractAttendanceController
{
    #[Route('/attendance', name: 'school_attendance', methods: ['GET'])]
    public function overview(School $school, AttendanceStatistics $statistics, #[MapQueryParameter(options: ['regexp' => '/^\d{4}-\d{2}$/'], flags: \FILTER_NULL_ON_FAILURE)] ?string $month = null): Response
    {
        $schoolYear = $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');
        $month = $this->monthOf($month);
        $months = $statistics->month($school, $schoolYear, $month);

        $byGrade = [];
        foreach ($months as $sectionMonth) {
            $byGrade[$sectionMonth->section->getGradeLevel()?->label() ?? 'No grade level'][] = $sectionMonth;
        }

        $first = $schoolYear->getStartDate()?->modify('first day of this month');
        $last = $schoolYear->getEndDate()?->modify('first day of this month');
        $monthChoices = [];
        for ($m = $first ?? $month; $m <= ($last ?? $month); $m = $m->modify('+1 month')) {
            $monthChoices[] = $m;
        }

        return $this->render('attendance/overview.html.twig', [
            'layout' => $this->layout(),
            'school' => $school,
            'school_year' => $schoolYear,
            'month' => $month,
            'months' => $monthChoices,
            'today' => $statistics->today($school, $schoolYear),
            'by_grade' => $byGrade,
            'follow_ups' => $statistics->followUps($months),
        ]);
    }

    #[Route('/sections/{section}/attendance/{month}', name: 'school_attendance_section', methods: ['GET'])]
    public function section(School $school, int $section, ?string $month = null): Response
    {
        $section = $this->requireSection($school, $section);
        $month = $this->monthOf($month);

        return $this->renderMonth($section, $month, [
            'layout' => $this->layout(),
            'month_route' => 'school_attendance_section',
            'route_params' => ['id' => $school->getId(), 'section' => $section->getId()],
            'sf2_route' => 'school_attendance_sf2',
            'day_route' => null,
        ], [
            'reopen_url' => $this->isGranted(AttendanceVoter::REOPEN, $section)
                ? $this->generateUrl('school_attendance_reopen', ['id' => $school->getId(), 'section' => $section->getId(), 'month' => $month->format('Y-m')])
                : null,
        ]);
    }

    #[Route('/sections/{section}/attendance/{month}/sf2', name: 'school_attendance_sf2', methods: ['GET'])]
    public function sf2(School $school, int $section, string $month): Response
    {
        return $this->downloadSf2($this->requireSection($school, $section), $this->monthOf($month));
    }

    #[Route('/sections/{section}/attendance/{month}/reopen', name: 'school_attendance_reopen', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"reopen-" ~ args["section"]'), tokenKey: '_token')]
    public function reopen(School $school, int $section, string $month): Response
    {
        $section = $this->requireSection($school, $section);
        $this->denyAccessUnlessGranted(AttendanceVoter::REOPEN, $section);
        $month = $this->monthOf($month);

        try {
            $this->recorder->reopen($section, $month);
            $this->addFlash('success', \sprintf('%s reopened; the adviser can change it and submit again.', $month->format('F Y')));
        } catch (AttendanceException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('school_attendance_section', ['id' => $school->getId(), 'section' => $section->getId(), 'month' => $month->format('Y-m')]);
    }

    private function requireSection(School $school, int $id): Section
    {
        $section = $this->sections->find($id);
        if (null === $section || $section->getSchool()->getId() !== $school->getId()) {
            throw $this->createNotFoundException();
        }

        return $section;
    }

    private function layout(): string
    {
        return $this->isGranted(User::ROLE_ADMIN) ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig';
    }
}
