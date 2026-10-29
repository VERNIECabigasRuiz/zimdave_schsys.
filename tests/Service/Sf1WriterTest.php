<?php

namespace App\Tests\Service;

use App\Sf1\Sf1Report;
use App\Sf1\Sf1Row;
use App\Sf1\Sf1Writer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

class Sf1WriterTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function row(string $lrn, string $name, string $sex): Sf1Row
    {
        return new Sf1Row($lrn, $name, $sex, '03-20-2012', 13, null, null, 'Christianity', null, 'Pagsabungan', 'Mandaue City', 'Cebu',
            'ARNOL, GERALD ABUHAT', 'MOLINA, ANGELLE', null, null, '09171234567', 'Face to Face', 'T/I Mandaue NHS 2025-06-16');
    }

    /** @param list<Sf1Row> $male @param list<Sf1Row> $female */
    private function report(array $male, array $female): Sf1Report
    {
        return new Sf1Report('303196', 'Region VII', 'Mandaue City', 'Pagsabungan National High School', '2025 - 2026', 'Grade 8', 'ARTISTIC',
            $male, $female, null, null, 'DAVE GARCIANO SOSMEÑA', 'RENE MONISIT PETANCIO', new \DateTimeImmutable('2026-01-27'));
    }

    /** Writes the report as .xls and reads it back, as a user's Excel would. */
    private function roundTrip(Sf1Report $report): Worksheet
    {
        $this->file = tempnam(sys_get_temp_dir(), 'sf1').'.xls';
        IOFactory::createWriter((new Sf1Writer())->write($report), 'Xls')->save($this->file);

        return IOFactory::load($this->file)->getSheet(0);
    }

    /** @return list<string> every non-empty cell value of the sheet */
    private function texts(Worksheet $sheet): array
    {
        $texts = [];
        foreach ($sheet->toArray(null, false, false, false) as $row) {
            foreach ($row as $value) {
                if (null !== $value && '' !== (string) $value) {
                    $texts[] = (string) $value;
                }
            }
        }

        return $texts;
    }

    public function testHeader(): void
    {
        $sheet = $this->roundTrip($this->report([], []));

        self::assertSame('School Form 1 (SF 1) School Register', $sheet->getCell('A1')->getValue());
        self::assertSame('303196', (string) $sheet->getCell('F3')->getValue());
        self::assertSame('Region VII', $sheet->getCell('K3')->getValue());
        self::assertSame('Mandaue City', $sheet->getCell('T3')->getValue());
        self::assertSame('Pagsabungan National High School', $sheet->getCell('F4')->getValue());
        self::assertSame('2025 - 2026', $sheet->getCell('T4')->getValue());
        self::assertSame('Grade 8', $sheet->getCell('AE4')->getValue());
        self::assertSame('ARTISTIC', $sheet->getCell('AM4')->getValue());
        self::assertSame('LRN', $sheet->getCell('A5')->getValue());
        self::assertSame('Barangay', $sheet->getCell('R6')->getValue());
    }

    public function testRowsAndTotals(): void
    {
        $sheet = $this->roundTrip($this->report(
            [$this->row('120017170001', 'BAÑEZ, REMUEL JR, BRAVO', 'M'), $this->row('120015170050', 'BONTILAO, JASTIN, PACRIS', 'M')],
            [$this->row('120015170024', 'ALMODIEL, SAMANTHA, PEREZ', 'F')],
        ));

        self::assertSame('120017170001', (string) $sheet->getCell('A7')->getValue());
        self::assertSame('BAÑEZ, REMUEL JR, BRAVO', $sheet->getCell('C7')->getValue());
        self::assertSame('M', $sheet->getCell('G7')->getValue());
        self::assertSame('03-20-2012', $sheet->getCell('H7')->getValue());
        self::assertSame('Pagsabungan', $sheet->getCell('R7')->getValue());
        self::assertSame('ARNOL, GERALD ABUHAT', $sheet->getCell('AB7')->getValue());
        self::assertSame('MOLINA, ANGELLE', $sheet->getCell('AF7')->getValue());
        self::assertSame('Face to Face', $sheet->getCell('AR7')->getValue());
        self::assertSame('T/I Mandaue NHS 2025-06-16', $sheet->getCell('AS7')->getValue());
        self::assertSame(2, (int) $sheet->getCell('A9')->getValue());
        self::assertSame('<=== TOTAL MALE', $sheet->getCell('C9')->getValue());
        self::assertSame('ALMODIEL, SAMANTHA, PEREZ', $sheet->getCell('C10')->getValue());
        self::assertSame(1, (int) $sheet->getCell('A11')->getValue());
        self::assertSame('<=== TOTAL FEMALE', $sheet->getCell('C11')->getValue());
        self::assertSame(3, (int) $sheet->getCell('A12')->getValue());
        self::assertSame('<=== COMBINED', $sheet->getCell('C12')->getValue());
    }

    public function testFooter(): void
    {
        $texts = $this->texts($this->roundTrip($this->report([$this->row('1', 'A, B, C', 'M')], [])));

        self::assertContains('List and Code of Indicators under REMARKS column', $texts);
        self::assertContains('DAVE GARCIANO SOSMEÑA', $texts);
        self::assertContains('RENE MONISIT PETANCIO', $texts);
        self::assertContains('(Signature of Adviser over Printed Name)', $texts);
        self::assertContains('(Signature of School Head over Printed Name)', $texts);
        self::assertContains('Generated on: Tuesday, January 27, 2026', $texts);
        self::assertNotContains('Generated thru LIS', $texts);
    }

    public function testRowsGrowToFitLongValues(): void
    {
        $long = new Sf1Row('1', 'A, B, C', 'M', '03-20-2012', 13, null, null, null, str_repeat('Purok Mahayag ', 7), null, null, null,
            null, null, null, null, null, 'Face to Face', null);

        $sheet = $this->roundTrip($this->report([$this->row('2', 'D, E, F', 'M'), $long], []));

        self::assertSame(24.0, (float) $sheet->getRowDimension(7)->getRowHeight());
        self::assertGreaterThanOrEqual(7 * 11, $sheet->getRowDimension(8)->getRowHeight());
    }

    public function testPageSetup(): void
    {
        $setup = $this->roundTrip($this->report([], []))->getPageSetup();

        self::assertSame(PageSetup::PAPERSIZE_LEGAL, $setup->getPaperSize());
        self::assertSame(PageSetup::ORIENTATION_LANDSCAPE, $setup->getOrientation());
    }

    public function testEmptyReport(): void
    {
        $sheet = $this->roundTrip($this->report([], []));

        self::assertSame(0, (int) $sheet->getCell('A7')->getValue());
        self::assertSame('<=== TOTAL MALE', $sheet->getCell('C7')->getValue());
        self::assertSame('<=== TOTAL FEMALE', $sheet->getCell('C8')->getValue());
        self::assertSame('<=== COMBINED', $sheet->getCell('C9')->getValue());
    }
}
