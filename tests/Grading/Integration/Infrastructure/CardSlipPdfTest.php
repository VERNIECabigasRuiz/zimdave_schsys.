<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Infrastructure;

use App\Grading\Domain\Service\CardSlip\CardSlipFileName;
use App\Grading\Domain\Service\CardSlip\CardSlipAttendance;
use App\Core\Domain\Service\Pdf\PdfRendererInterface;
use App\Grading\Domain\Service\CardSlip\CardSlip;
use App\Grading\Domain\Service\CardSlip\CardSlipLine;
use App\Grading\Infrastructure\CardSlip\CardSlipPdf;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

class CardSlipPdfTest extends KernelTestCase
{
    private function pdf(): CardSlipPdf
    {
        self::bootKernel();

        return new CardSlipPdf(
            static::getContainer()->get(PdfRendererInterface::class),
            static::getContainer()->get(Environment::class),
            static::getContainer()->get(ImageProcessorInterface::class),
        );
    }

    private static function slip(
        string $name = 'DELA CRUZ, JUAN',
        bool $draft = false,
        ?string $logoPath = null,
    ): CardSlip {
        return new CardSlip(
            'Pagsabungan National High School',
            'Pagsabungan, Mandaue City',
            $logoPath,
            'Term 1',
            '2026-2027',
            $name,
            '303196150001',
            'Grade 7 – ACTIVE',
            'ANA REYES',
            [
                new CardSlipLine('Filipino', 88, 'Passed', null),
                new CardSlipLine('Mathematics', 72, 'Failed', null),
                new CardSlipLine('MAPEH', null, '', 'Music 85 · Arts 88 · PE 90 · Health —'),
            ],
            null,
            null,
            new CardSlipAttendance(60, 58, 2, 3),
            ['Participates actively in class activities.', 'Please guide your child in reviewing lessons at home.'],
            $draft,
            1,
            [],
            [],
            null,
        );
    }

    private static function pages(string $pdf): int
    {
        return (int) preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    public function testTwoSlipsPerPageAndAnOddLastSlipAlone(): void
    {
        $html = $this->pdf()->html([self::slip('A'), self::slip('B'), self::slip('C')]);

        self::assertSame(3, preg_match_all('/class="slip[ "]/', $html));
        self::assertSame(2, preg_match_all('/class="page[ "]/', $html));
        $lastPage = substr($html, (int) strrpos($html, 'class="page'));
        self::assertSame(1, preg_match_all('/class="slip[ "]/', $lastPage));
    }

    public function testTheSchoolLogoIsEmbeddedAndAMissingOneLeftOut(): void
    {
        // Initialize
        $pdf = $this->pdf();
        $path = 'uploads/logos/' . bin2hex(random_bytes(16)) . '.webp';
        $file = static::getContainer()->get(ImageProcessorInterface::class)->absolutePath($path);
        @mkdir(\dirname($file), 0777, true);
        $image = imagecreatetruecolor(40, 40);
        self::assertNotFalse($image);
        imagewebp($image, $file);

        // Execute
        try {
            $html = $pdf->html([self::slip(logoPath: $path), self::slip('SANTOS, ANA', logoPath: $path)]);
        } finally {
            unlink($file);
        }
        $missing = $pdf->html([self::slip(logoPath: 'uploads/logos/missing.webp')]);

        // Assert
        self::assertSame(2, substr_count($html, '<img src="data:image/png;base64,'));
        self::assertStringNotContainsString('<img', $missing);
    }

    public function testCaptions(): void
    {
        $html = $this->pdf()->html([self::slip()]);

        $texts = [
            'CARD SLIP · Term 1',
            'S.Y. 2026-2027',
            'Adviser',
            'Parent/Guardian (signature over printed name)',
            'Date:',
            'DELA CRUZ, JUAN',
            '303196150001',
            'Grade 7 – ACTIVE',
            'ANA REYES',
            'Music 85 · Arts 88 · PE 90 · Health —',
            'Incomplete',
            'Participates actively in class activities.',
        ];
        foreach ($texts as $text) {
            self::assertStringContainsString($text, $html);
        }
    }

    public function testDraftMarkOnlyOnDraftSlips(): void
    {
        $pdf = $this->pdf();

        self::assertStringNotContainsString('DRAFT – grades not final', $pdf->html([self::slip()]));
        self::assertSame(
            1,
            substr_count($pdf->html([self::slip(), self::slip(draft: true)]), 'DRAFT – grades not final'),
        );
        // dark enough to survive a light laser print, still behind the content
        self::assertMatchesRegularExpression(
            '/\.draft \{[^}]*z-index: -1;[^}]*color: #c3cad4;/',
            $pdf->html([self::slip(draft: true)]),
        );
    }

    public function testRenderedPdfHasOnePagePerTwoSlips(): void
    {
        $pdf = $this->pdf();

        $three = $pdf->render([self::slip('A'), self::slip('B', true), self::slip('C')]);
        self::assertStringStartsWith('%PDF-', $three);
        self::assertSame(2, self::pages($three));
        self::assertSame(1, self::pages($pdf->render([self::slip()])));
    }

    public function testFilenames(): void
    {
        $sy = (new SchoolYear())->setName('2026-2027');
        $section = (new Section(new School(), $sy))->setGradeLevel(GradeLevel::Grade7)->setName('Active');
        $period = new GradingPeriod($sy, 1);

        self::assertSame('Card slips - Grade 7 ACTIVE - Term 1.pdf', CardSlipFileName::forSection($section, $period));
        self::assertSame(
            'Card slips - Grade 7 A-B - Term 1.pdf',
            CardSlipFileName::forSection($section->setName('a/b'), $period),
        );
        self::assertSame('Card slip - DELA CRUZ, JUAN - Term 1.pdf', CardSlipFileName::forSlip(self::slip()));
        self::assertSame(
            'Card slip - DELA CRUZ-JUAN - Term 1.pdf',
            CardSlipFileName::forSlip(self::slip('DELA CRUZ/JUAN')),
        );
    }
}
