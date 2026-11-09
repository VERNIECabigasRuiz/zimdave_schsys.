<?php

declare(strict_types=1);

namespace App\Tests\Staff\Integration\Infrastructure;

use App\Core\Domain\Service\Pdf\PdfRendererInterface;
use App\Staff\Domain\Service\StaffId\StaffIdCard;
use App\Staff\Domain\Service\StaffId\StaffIdFileName;
use App\Staff\Domain\Service\StaffId\StaffIdPdfInterface;
use App\Staff\Domain\Service\StaffId\StaffIdSize;
use App\Staff\Infrastructure\StaffId\StaffIdPdf;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

class StaffIdPdfTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function pdf(): StaffIdPdfInterface
    {
        return static::getContainer()->get(StaffIdPdfInterface::class);
    }

    public static function card(string $surname = 'DELA CRUZ', string $given = 'JUAN A.'): StaffIdCard
    {
        return new StaffIdCard(
            fullName: $given . ' ' . $surname,
            surname: $surname,
            givenNames: $given,
            callName: 'Sir JUAN',
            position: 'Teacher I',
            employeeNo: '4693599',
            tin: '123-456-789-000',
            philhealthNo: '12-123456789-0',
            pagibigNo: '1234-5678-9012',
            bloodType: 'O+',
            gsisPolicyNo: '2003334189',
            emergencyName: 'Maria Dela Cruz',
            emergencyContact: '0917 123 4567',
            region: 'Region VII',
            regionLabel: 'REGION VII - CENTRAL VISAYAS',
            division: 'Mandaue City',
            schoolName: 'Pagsabungan National High School',
            schoolAddress: 'Z. Estreras, Pagsabungan, Mandaue City',
            website: 'facebook.com/pnhs',
            photo: null,
            logo: null,
            seal: null,
            headName: 'ROSA CRUZ',
            headPosition: 'Principal II',
            headSignature: null,
        );
    }

    public function testBigHtmlHasCardContent(): void
    {
        $html = $this->pdf()->html(self::card(), StaffIdSize::Big);

        foreach (
            [
                'Sir JUAN',
                'JUAN A. DELA CRUZ',
                'Teacher I',
                'ROSA CRUZ',
                'Principal II',
                'REGION VII - CENTRAL VISAYAS',
                'SCHOOLS DIVISION OF MANDAUE CITY',
                'width: 100mm',
            ] as $text
        ) {
            self::assertStringContainsString($text, $html);
        }
    }

    public function testBothSizesPrintOnTheTemplateImages(): void
    {
        // Initialize
        $pdf = $this->pdf();

        // Execute
        $big = $pdf->html(self::card(), StaffIdSize::Big);
        $small = $pdf->html(self::card(), StaffIdSize::Small);

        // Assert
        self::assertSame(1, substr_count($big, 'class="bg" src="data:image/jpeg;base64,'));
        self::assertSame(2, substr_count($small, 'class="bg" src="data:image/jpeg;base64,'));
    }

    public function testSmallHtmlHasBothSides(): void
    {
        $html = $this->pdf()->html(self::card(), StaffIdSize::Small);

        foreach (
            [
                'DELA CRUZ,',
                'EMPLOYEE NO: 4693599',
                'SCHOOLS DIVISION',
                'MANDAUE CITY',
                'TEACHER I',
                'Maria Dela Cruz',
                '0917 123 4567',
                '123-456-789-000',
                '2003334189',
                '12-123456789-0',
                '1234-5678-9012',
                'O+',
                'ROSA CRUZ',
                'PAGSABUNGAN NATIONAL HIGH SCHOOL',
                'facebook.com/pnhs',
                'width: 54mm',
            ] as $text
        ) {
            self::assertStringContainsString($text, $html);
        }
    }

    /** A fixed height with a capped width squashes a wide signature; both limits must be maxima. */
    public function testSignatureKeepsItsShape(): void
    {
        foreach (StaffIdSize::cases() as $size) {
            $html = $this->pdf()->html(self::card(), $size);

            self::assertMatchesRegularExpression(
                '/\.signature img \{ max-width: [\d.]+mm; max-height: [\d.]+mm; \}/',
                $html,
                $size->value,
            );
        }
    }

    public function testRenderIsPdf(): void
    {
        self::assertStringStartsWith('%PDF-', $this->pdf()->render(self::card(), StaffIdSize::Big));
        self::assertStringStartsWith('%PDF-', $this->pdf()->render(self::card(), StaffIdSize::Small));
    }

    public function testFilename(): void
    {
        self::assertSame('ID (Big) - DELA CRUZ, JUAN.pdf', StaffIdFileName::forCard(self::card(), StaffIdSize::Big));
        self::assertSame(
            'ID (Small) - DELA CRUZ, JUAN.pdf',
            StaffIdFileName::forCard(self::card(), StaffIdSize::Small),
        );

        $peña = self::card('PEÑA', 'JUAN A. JR.');
        self::assertSame('ID (Small) - PEÑA, JUAN.pdf', StaffIdFileName::forCard($peña, StaffIdSize::Small));
    }

    public function testWithoutTheTemplateImagesTheCardPrintsOnBlank(): void
    {
        // Initialize
        $pdf = new StaffIdPdf(
            static::getContainer()->get(PdfRendererInterface::class),
            static::getContainer()->get(Environment::class),
            sys_get_temp_dir() . '/no-project-' . bin2hex(random_bytes(4)),
        );

        // Execute
        $html = $pdf->html(self::card(), StaffIdSize::Big);

        // Assert
        self::assertStringContainsString('Sir JUAN', $html);
        self::assertStringNotContainsString('data:image/jpeg', $html);
    }
}
