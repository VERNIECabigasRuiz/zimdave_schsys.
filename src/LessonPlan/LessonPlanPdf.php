<?php

namespace App\LessonPlan;

use App\Entity\LessonPlan;
use App\Entity\User;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\ServiceRecordEntryRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

use function Symfony\Component\String\u;

/** An ILAW lesson plan as a PDF on letter landscape, laid out like DepEd's Daily Lesson Log – ILAW format. */
class LessonPlanPdf
{
    /** 11 × 8.5 in, in points */
    private const LETTER_LANDSCAPE = [0, 0, 792, 612];

    public function __construct(
        private readonly Environment $twig,
        private readonly ServiceRecordEntryRepository $entries,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly SchoolYearRepository $schoolYears,
    ) {
    }

    public function render(LessonPlan $plan): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($plan));
        $dompdf->setPaper(self::LETTER_LANDSCAPE);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function html(LessonPlan $plan): string
    {
        $load = $plan->getLoad();
        $teacher = $load->getTeacher();
        $school = $load->getSection()->getSchool();
        $schoolYear = $this->schoolYears->findCurrent();
        $principal = null === $schoolYear ? null : $this->assignments->findPrincipal($school, $schoolYear)?->getUser();

        return $this->twig->render('lesson_plans/pdf.html.twig', [
            'plan' => $plan,
            'school' => $school,
            'competencies' => LessonPlan::bullets($plan->getCompetencies()),
            'references' => LessonPlan::bullets($plan->getReferences()),
            'teacher' => $this->signatory($teacher, 'Teacher'),
            'principal' => null === $principal ? null : $this->signatory($principal, 'School Principal'),
        ]);
    }

    /** "ILAW - Mathematics - ARTISTIC - 2026-11-30.pdf"; "/", "\" and "%" become "-" */
    public static function filename(LessonPlan $plan): string
    {
        $load = $plan->getLoad();
        $name = \sprintf('ILAW - %s - %s - %s.pdf', $load->getSubject()->getName(), $load->getSection()->getName(), $plan->getWeekStart()->format('Y-m-d'));

        return (string) preg_replace('/[\/\\\\%]/', '-', $name);
    }

    public static function asciiFilename(LessonPlan $plan): string
    {
        return (string) u(self::filename($plan))->ascii();
    }

    /** @return array{name: string, position: string} the person's name in capitals and their Present-row position */
    private function signatory(User $user, string $fallback): array
    {
        return [
            'name' => mb_strtoupper($user->getFullName()),
            'position' => $this->entries->findCurrent($user)?->getDesignation() ?? $fallback,
        ];
    }
}
