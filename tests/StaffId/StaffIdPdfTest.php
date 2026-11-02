<?php

namespace App\Tests\StaffId;

use App\StaffId\StaffIdCard;
use App\StaffId\StaffIdPdf;
use App\StaffId\StaffIdSize;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

class StaffIdPdfTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    /** Built by hand: until a controller uses it, the container drops the service. */
    private function pdf(): StaffIdPdf
    {
        return new StaffIdPdf(static::getContainer()->get(Environment::class), static::getContainer()->getParameter('kernel.project_dir'));
    }

    public static function card(string $surname = 'DELA CRUZ', string $given = 'JUAN A.'): StaffIdCard
    {
        return new StaffIdCard(
            fullName: $given.' '.$surname,
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

        foreach (['Sir JUAN', 'JUAN A. DELA CRUZ', 'Teacher I', 'ROSA CRUZ', 'Principal II', 'REGION VII - CENTRAL VISAYAS', 'SCHOOLS DIVISION OF MANDAUE CITY', 'width: 100mm'] as $text) {
            self::assertStringContainsString($text, $html);
        }
    }

    public function testSmallHtmlHasBothSides(): void
    {
        $html = $this->pdf()->html(self::card(), StaffIdSize::Small);

        foreach (['DELA CRUZ,', 'EMPLOYEE NO: 4693599', 'SCHOOLS DIVISION', 'MANDAUE CITY', 'TEACHER I', 'Maria Dela Cruz', '0917 123 4567', '123-456-789-000', '2003334189', '12-123456789-0', '1234-5678-9012', 'O+', 'ROSA CRUZ', 'PAGSABUNGAN NATIONAL HIGH SCHOOL', 'facebook.com/pnhs', 'width: 54mm'] as $text) {
            self::assertStringContainsString($text, $html);
        }
    }

    public function testRenderIsPdf(): void
    {
        self::assertStringStartsWith('%PDF-', $this->pdf()->render(self::card(), StaffIdSize::Big));
        self::assertStringStartsWith('%PDF-', $this->pdf()->render(self::card(), StaffIdSize::Small));
    }

    public function testFilename(): void
    {
        self::assertSame('ID (Big) - DELA CRUZ, JUAN.pdf', StaffIdPdf::filename(self::card(), StaffIdSize::Big));
        self::assertSame('ID (Small) - DELA CRUZ, JUAN.pdf', StaffIdPdf::filename(self::card(), StaffIdSize::Small));

        $peña = self::card('PEÑA', 'JUAN A. JR.');
        self::assertSame('ID (Small) - PEÑA, JUAN.pdf', StaffIdPdf::filename($peña, StaffIdSize::Small));
        self::assertSame('ID (Small) - PENA, JUAN.pdf', StaffIdPdf::asciiFilename($peña, StaffIdSize::Small));
    }
}
