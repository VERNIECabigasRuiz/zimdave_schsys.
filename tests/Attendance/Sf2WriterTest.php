<?php

namespace App\Tests\Attendance;

use App\Attendance\Sf2Report;
use App\Attendance\Sf2Row;
use App\Attendance\Sf2Summary;
use App\Attendance\Sf2Writer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

class Sf2WriterTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'sf2').'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function row(string $name, bool $male, array $cells = [], int $absences = 0, int $tardies = 0, ?string $remark = null): Sf2Row
    {
        return new Sf2Row(1, 1, $name, $male, array_pad($cells, 20, Sf2Row::PRESENT), $absences, $tardies, $remark, false, false);
    }

    private function report(int $males = 2, int $females = 2): Sf2Report
    {
        $days = [];
        for ($d = new \DateTimeImmutable('2025-08-01'); \count($days) < 20; $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') <= 5 && '21' !== $d->format('d')) {
                $days[] = $d;
            }
        }
        $male = [$this->row('ARNOL, GERALD JAMES, MOLINA', true, [Sf2Row::ABSENT, Sf2Row::LATE, Sf2Row::CUTTING], 1, 2)];
        for ($i = 1; $i < $males; ++$i) {
            $male[] = $this->row(\sprintf('MALE, LEARNER %02d, -', $i), true);
        }
        $female = [$this->row('CRUZ, ANA, -', false, [Sf2Row::NOT_COUNTED], remark: 'T/I Mandaue City NHS 2025-08-04')];
        for ($i = 1; $i < $females; ++$i) {
            $female[] = $this->row(\sprintf('FEMALE, LEARNER %02d, -', $i), false);
        }

        return new Sf2Report(
            schoolId: '303196',
            schoolName: 'Pagsabungan National High School',
            schoolYear: '2025 - 2026',
            month: new \DateTimeImmutable('2025-08-01'),
            gradeLevel: 'Grade 8',
            section: 'ARTISTIC',
            days: $days,
            taken: array_fill(0, 20, true),
            male: $male,
            female: $female,
            malePresent: array_merge([$males - 1], array_fill(0, 19, $males)),
            femalePresent: array_merge([$females - 1], array_fill(0, 19, $females)),
            summary: new Sf2Summary(20, [2, 2, 4], [0, 1, 1], [2, 2, 4], [100.0, 100.0, 100.0], [1.95, 1.95, 3.9], [97.5, 97.5, 97.5], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 1, 1]),
            adviserName: 'DAVE SOSMEÑA',
            schoolHeadName: 'RENE PETANCIO',
            submission: null,
            missingDays: [],
            daysOff: ['Aug 21 – Ninoy Aquino Day'],
        );
    }

    private function written(Sf2Report $report): Worksheet
    {
        (new Sf2Writer(\dirname(__DIR__, 2)))->save($report, $this->file);

        return IOFactory::load($this->file)->getActiveSheet();
    }

    public function testHeader(): void
    {
        $sheet = $this->written($this->report());

        self::assertSame('303196', (string) $sheet->getCell('C6')->getValue());
        self::assertSame('2025 - 2026', $sheet->getCell('K6')->getValue());
        self::assertSame('August', $sheet->getCell('X6')->getValue());
        self::assertSame('Pagsabungan National High School', $sheet->getCell('C8')->getValue());
        self::assertSame('Grade 8', $sheet->getCell('X8')->getValue());
        self::assertSame('ARTISTIC', $sheet->getCell('AC8')->getValue());
    }

    public function testDaysAndMarks(): void
    {
        $sheet = $this->written($this->report());

        self::assertSame(1, $sheet->getCell('D11')->getValue());
        self::assertSame('F', $sheet->getCell('D12')->getValue());
        self::assertSame(0, $sheet->getStyle('D12')->getAlignment()->getTextRotation());
        self::assertSame(4, $sheet->getCell('E11')->getValue());
        self::assertSame('M', $sheet->getCell('E12')->getValue());
        self::assertSame(29, $sheet->getCell('W11')->getValue()); // the 20th school day, Aug 29
        self::assertNull($sheet->getCell('X11')->getValue());
        self::assertSame(1, $sheet->getCell('A14')->getValue());
        self::assertSame('ARNOL, GERALD JAMES, MOLINA', $sheet->getCell('B14')->getValue());
        self::assertSame('x', $sheet->getCell('D14')->getValue());
        self::assertSame(Fill::FILL_GRADIENT_LINEAR, $sheet->getStyle('E14')->getFill()->getFillType());
        self::assertSame(Fill::FILL_GRADIENT_LINEAR, $sheet->getStyle('F14')->getFill()->getFillType());
        self::assertNotSame(Fill::FILL_GRADIENT_LINEAR, $sheet->getStyle('G14')->getFill()->getFillType());
        self::assertSame(1, $sheet->getCell('AC14')->getValue());
        self::assertSame(2, $sheet->getCell('AD14')->getValue());
        self::assertSame('T/I Mandaue City NHS 2025-08-04', $sheet->getCell('AE36')->getValue());
    }

    public function testHalfShadesAreSharp(): void
    {
        (new Sf2Writer(\dirname(__DIR__, 2)))->save($this->report(), $this->file);
        $zip = new \ZipArchive();
        $zip->open($this->file);
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $zip->close();

        self::assertSame(2, substr_count($styles, '<stop position="0.5">'));
    }

    public function testTotalsRows(): void
    {
        $sheet = $this->written($this->report());

        self::assertStringContainsString('MALE  | TOTAL Per Day', $sheet->getCell('A35')->getValue());
        self::assertSame(1, $sheet->getCell('D35')->getValue());
        self::assertSame(2, $sheet->getCell('E35')->getValue());
        self::assertSame(1, $sheet->getCell('AC35')->getValue());
        self::assertSame(2, $sheet->getCell('AD35')->getValue());
        // the template has a rotated text style and an "00000" format in this row
        self::assertSame(0, $sheet->getStyle('D35')->getAlignment()->getTextRotation());
        self::assertSame('General', $sheet->getStyle('AC35')->getNumberFormat()->getFormatCode());
        self::assertStringContainsString('FEMALE  | TOTAL Per Day', $sheet->getCell('A61')->getValue());
        self::assertSame(2, $sheet->getCell('E61')->getValue());
        self::assertSame(2, $sheet->getCell('D62')->getValue());
        self::assertSame(4, $sheet->getCell('E62')->getValue());
    }

    public function testSummaryValues(): void
    {
        $sheet = $this->written($this->report());

        self::assertSame('August', $sheet->getCell('AB65')->getValue());
        self::assertSame(20, $sheet->getCell('AG64')->getValue());
        self::assertSame([2, 2, 4], [$sheet->getCell('AH66')->getValue(), $sheet->getCell('AI66')->getValue(), $sheet->getCell('AJ66')->getValue()]);
        self::assertSame(1, $sheet->getCell('AJ68')->getValue());
        self::assertSame(4, $sheet->getCell('AJ70')->getValue());
        self::assertEquals(100, $sheet->getCell('AJ72')->getValue());
        self::assertEquals(3.9, $sheet->getCell('AJ74')->getValue());
        self::assertEquals(97.5, $sheet->getCell('AJ75')->getValue());
        self::assertSame(0, $sheet->getCell('AJ77')->getValue());
        self::assertSame(1, $sheet->getCell('AJ83')->getValue());
        // the template formats these as percentages and rotates one; the values are already percents
        self::assertStringNotContainsString('%', $sheet->getStyle('AH75')->getNumberFormat()->getFormatCode());
        self::assertStringNotContainsString('%', $sheet->getStyle('AH77')->getNumberFormat()->getFormatCode());
        self::assertSame(0, $sheet->getStyle('AH77')->getAlignment()->getTextRotation());
        self::assertEquals(11, $sheet->getStyle('AH77')->getFont()->getSize());
        self::assertSame('General', $sheet->getStyle('AJ72')->getNumberFormat()->getFormatCode());
    }

    public function testExtraRows(): void
    {
        $sheet = $this->written($this->report(males: 23, females: 27));

        // 23 males from row 14: the male total row moves from 35 to 37, the female block starts at 38
        self::assertStringContainsString('MALE  | TOTAL Per Day', $sheet->getCell('A37')->getValue());
        self::assertSame('MALE, LEARNER 22, -', $sheet->getCell('B36')->getValue());
        self::assertSame('CRUZ, ANA, -', $sheet->getCell('B38')->getValue());
        // 27 females from row 38: the female total row is at 65, combined at 66
        self::assertSame('FEMALE, LEARNER 26, -', $sheet->getCell('B64')->getValue());
        self::assertStringContainsString('FEMALE  | TOTAL Per Day', $sheet->getCell('A65')->getValue());
        self::assertStringContainsString('Combined TOTAL PER DAY', $sheet->getCell('A66')->getValue());
        self::assertArrayHasKey('B64:C64', $sheet->getMergeCells());
        // the summary box moved down by 4 rows too
        self::assertSame(20, $sheet->getCell('AG68')->getValue());
        // still two pages, as the template prints
        self::assertTrue($sheet->getPageSetup()->getFitToPage());
        self::assertSame(1, $sheet->getPageSetup()->getFitToWidth());
        self::assertSame(2, $sheet->getPageSetup()->getFitToHeight());
    }

    public function testSignatures(): void
    {
        $sheet = $this->written($this->report());

        self::assertSame('DAVE SOSMEÑA', $sheet->getCell('AD88')->getValue());
        self::assertSame('RENE PETANCIO', $sheet->getCell('AD92')->getValue());
        // the template's 12 pt row clips the tilde of a capital Ñ
        self::assertGreaterThanOrEqual(18, $sheet->getRowDimension(88)->getRowHeight());
        self::assertGreaterThanOrEqual(18, $sheet->getRowDimension(92)->getRowHeight());
        // the taller rows must not push the foot onto a third page
        self::assertTrue($sheet->getPageSetup()->getFitToPage());
        self::assertSame(2, $sheet->getPageSetup()->getFitToHeight());
    }
}
