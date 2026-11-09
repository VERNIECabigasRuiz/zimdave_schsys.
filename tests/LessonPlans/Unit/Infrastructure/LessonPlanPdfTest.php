<?php

declare(strict_types=1);

namespace App\Tests\LessonPlans\Unit\Infrastructure;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Core\Domain\Service\Pdf\PdfRendererInterface;
use App\LessonPlans\Domain\Model\LessonPlan;
use App\LessonPlans\Domain\Service\Pdf\LessonPlanPrintout;
use App\LessonPlans\Infrastructure\Pdf\LessonPlanPdf;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

final class LessonPlanPdfTest extends TestCase
{
    public function testThePdfIsTheTemplateOnLetterLandscape(): void
    {
        // Initialize
        $school = new School();
        $schoolYear = new SchoolYear();
        $teacher = new User();
        $load = new TeachingLoad(new Section($school, $schoolYear), new Subject($school, $schoolYear), $teacher);
        $printout = new LessonPlanPrintout(
            new LessonPlan($load, new \DateTimeImmutable('2026-11-30'), new \DateTimeImmutable()),
            $school,
            ['C1'],
            [],
            ['name' => 'TESS REYES', 'position' => 'Teacher'],
            null,
        );
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')
            ->with('@LessonPlans/lesson_plans/pdf.html.twig', self::callback(
                static fn (array $vars) => ['C1'] === $vars['competencies'] && null === $vars['principal'],
            ))
            ->willReturn('<html>plan</html>');
        $renderer = $this->createMock(PdfRendererInterface::class);
        $renderer->expects(self::once())->method('render')->with('<html>plan</html>', [0, 0, 792, 612])->willReturn(
            '%PDF',
        );

        // Execute
        $pdf = (new LessonPlanPdf($renderer, $twig))->render($printout);

        // Assert
        self::assertSame('%PDF', $pdf);
    }
}
