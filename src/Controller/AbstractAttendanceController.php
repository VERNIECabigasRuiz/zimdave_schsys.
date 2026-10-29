<?php

namespace App\Controller;

use App\Attendance\AttendanceRecorder;
use App\Attendance\AttendanceRules;
use App\Attendance\Sf2ReportBuilder;
use App\Attendance\Sf2Roster;
use App\Attendance\Sf2Writer;
use App\Entity\Section;
use App\Repository\RollCallRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\SectionRepository;
use App\Security\Voter\AttendanceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The attendance pages shared by the adviser's class and the school pages: a section's month
 * grid with its SF2 figures, and the SF2 download. Subclasses decide the routes and who may write.
 */
abstract class AbstractAttendanceController extends AbstractController
{
    public function __construct(
        protected readonly Sf2ReportBuilder $reports,
        protected readonly Sf2Writer $writer,
        protected readonly AttendanceRules $rules,
        protected readonly AttendanceRecorder $recorder,
        protected readonly Sf2Roster $roster,
        protected readonly RollCallRepository $rollCalls,
        protected readonly SchoolYearRepository $schoolYears,
        protected readonly SectionRepository $sections,
    ) {
    }

    /** The 1st of the month "Y-m" names, or of the current month. */
    protected function monthOf(?string $month): \DateTimeImmutable
    {
        if (null === $month) {
            return $this->rules->today()->modify('first day of this month');
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $month.'-01') ?: throw $this->createNotFoundException();
    }

    /**
     * @param array{layout: string, month_route: string, route_params: array<string, mixed>, sf2_route: string, day_route: ?string} $routes
     * @param array<string, mixed>                                                                                                  $vars
     */
    protected function renderMonth(Section $section, \DateTimeImmutable $month, array $routes, array $vars = []): Response
    {
        $this->denyAccessUnlessGranted(AttendanceVoter::VIEW, $section);
        $schoolYear = $section->getSchoolYear();
        $first = $schoolYear->getStartDate()?->modify('first day of this month');
        $last = $schoolYear->getEndDate()?->modify('first day of this month');
        $previous = $month->modify('-1 month');
        $next = $month->modify('+1 month');

        return $this->render('attendance/month.html.twig', $routes + $vars + [
            'section' => $section,
            'report' => $this->reports->build($section, $month),
            'today' => $this->rules->today(),
            'previous' => null === $first || $previous >= $first ? $previous : null,
            'next' => null === $last || $next <= $last ? $next : null,
        ]);
    }

    protected function downloadSf2(Section $section, \DateTimeImmutable $month): Response
    {
        $this->denyAccessUnlessGranted(AttendanceVoter::VIEW, $section);
        $report = $this->reports->build($section, $month);

        $file = tempnam(sys_get_temp_dir(), 'sf2');
        try {
            $this->writer->save($report, $file);
            $content = file_get_contents($file);
        } finally {
            unlink($file);
        }

        $fileName = $report->fileName();

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            // section names may have an Ñ; old browsers get underscores
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, preg_replace('/[^\x20-\x7E]/', '_', $fileName)),
        ]);
    }
}
