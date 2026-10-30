<?php

namespace App\Tests\Nutrition;

use App\Attendance\RosterEntry;
use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\Learner;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\ShsTrack;
use App\Entity\User;
use App\Nutrition\BmiCategory;
use App\Nutrition\HfaCategory;
use App\Nutrition\NutritionPeriod;
use App\Nutrition\NutritionResult;
use App\Nutrition\Sf8Report;
use App\Nutrition\Sf8Row;
use App\Nutrition\Sf8Status;
use App\Nutrition\Sf8Summary;
use App\Nutrition\Sf8Writer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

class Sf8WriterTest extends TestCase
{
    private string $file;
    private Section $section;
    private User $adviser;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'sf8').'.xlsx';
        $school = (new School())->setSchoolId('303196')->setName('Pagsabungan National High School')
            ->setDistrict('Mandaue City II')->setDivision('Mandaue City')->setRegion('Region VII');
        $sy = (new SchoolYear())->setName('2025-2026')->setStartDate(new \DateTimeImmutable('2025-06-01'))->setEndDate(new \DateTimeImmutable('2026-04-30'));
        $this->section = (new Section($school, $sy))->setGradeLevel(GradeLevel::Grade8)->setName('Artistic');
        $this->adviser = new User();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function row(string $name, bool $male, ?float $weight = null, ?float $height = null, ?int $ageMonths = 159, ?BmiCategory $bmi = null, ?HfaCategory $hfa = null, ?string $remarks = null): Sf8Row
    {
        $learner = new Learner($this->adviser, new \DateTimeImmutable('2025-05-01'));
        $enrollment = new Enrollment($learner, $this->section->getSchoolYear(), $this->section, $this->adviser, new \DateTimeImmutable('2025-05-20'));
        $entry = new RosterEntry($enrollment, $male, $name, new \DateTimeImmutable('2025-06-16'), null, false, false, false, false, false, null);
        $weighed = null !== $weight && null !== $height;
        $bmiValue = $weighed ? $weight / ($height * $height) : null;

        return new Sf8Row($entry, '1200171700'.str_pad((string) crc32($name) % 100, 2, '0', \STR_PAD_LEFT), new \DateTimeImmutable('2012-05-01'),
            new NutritionResult($ageMonths, $bmiValue, $bmi, $hfa), $weight, $height, $remarks, $weighed);
    }

    /** @param list<Sf8Row>|null $males */
    private function report(?array $males = null, NutritionPeriod $period = NutritionPeriod::Baseline): Sf8Report
    {
        $males ??= [
            $this->row('ARNOL, GERALD JAMES, MOLINA', true, 31.5, 1.45, bmi: BmiCategory::Normal, hfa: HfaCategory::Normal, remarks: 'Dewormed'),
            $this->row('BAÑEZ, LEARNER, -', true),
        ];
        $females = [
            $this->row('CRUZ, ANA, -', false, 18.0, 1.05, ageMonths: 58),
            $this->row('DIAZ, LEARNER, -', false, 55.0, 1.5, bmi: BmiCategory::Overweight, hfa: HfaCategory::Tall),
        ];
        $rows = [...$males, ...$females];

        return new Sf8Report(
            section: $this->section,
            period: $period,
            weighedOn: new \DateTimeImmutable('2025-08-20'),
            dateSet: true,
            rows: $rows,
            summary: Sf8Summary::fromRows($rows),
            status: Sf8Status::Draft,
            submission: null,
            adviserName: 'DAVE SOSMEÑA',
            principalName: 'RENE PETANCIO',
            principalPosition: 'School Principal',
            certifierName: 'Ma. Luz Cabrera',
            certifierPosition: 'School Nurse',
        );
    }

    private function writer(): Sf8Writer
    {
        return new Sf8Writer(\dirname(__DIR__, 2));
    }

    private function saved(Sf8Report $report): Spreadsheet
    {
        IOFactory::createWriter($this->writer()->write($report), 'Xlsx')->save($this->file);

        return IOFactory::load($this->file);
    }

    private function written(Sf8Report $report): Worksheet
    {
        return $this->saved($report)->getActiveSheet();
    }

    public function testHeader(): void
    {
        $sheet = $this->written($this->report());

        self::assertSame('Nutritional Status', $sheet->getTitle());
        self::assertSame('(For All Grade Levels) — Baseline', $sheet->getCell('A4')->getValue());
        self::assertSame('Pagsabungan National High School', $sheet->getCell('E5')->getValue());
        self::assertSame('Mandaue City II', $sheet->getCell('I5')->getValue());
        self::assertSame('Mandaue City', $sheet->getCell('L5')->getValue());
        self::assertSame('Region VII', $sheet->getCell('O5')->getValue());
        self::assertSame('303196', (string) $sheet->getCell('C7')->getValue());
        self::assertSame('Grade 8', $sheet->getCell('F7')->getValue());
        self::assertSame('ARTISTIC', $sheet->getCell('H7')->getValue());
        self::assertSame('N/A', $sheet->getCell('L7')->getValue());
        self::assertSame('2025-2026', $sheet->getCell('O7')->getValue());
    }

    public function testEndlineAndTrack(): void
    {
        $this->section->setGradeLevel(GradeLevel::Grade11)->setTrack(ShsTrack::Academic)->setStrand('STEM');
        $sheet = $this->written($this->report(period: NutritionPeriod::Endline));

        self::assertSame('(For All Grade Levels) — Endline', $sheet->getCell('A4')->getValue());
        self::assertSame('Grade 11', $sheet->getCell('F7')->getValue());
        self::assertSame('Academic / STEM', $sheet->getCell('L7')->getValue());
    }

    public function testRows(): void
    {
        $sheet = $this->written($this->report());

        self::assertSame(1, $sheet->getCell('A12')->getValue());
        self::assertSame('ARNOL, GERALD JAMES, MOLINA', $sheet->getCell('C12')->getValue());
        self::assertMatchesRegularExpression('/^1200171700\d\d$/', (string) $sheet->getCell('B12')->getValue());
        self::assertSame('2012-05-01', Date::excelToDateTimeObject($sheet->getCell('G12')->getValue())->format('Y-m-d'));
        self::assertSame('mm/dd/yyyy', $sheet->getStyle('G12')->getNumberFormat()->getFormatCode());
        self::assertSame(13, $sheet->getCell('H12')->getValue());
        self::assertEqualsWithDelta(31.5, $sheet->getCell('I12')->getValue(), 0.0001);
        self::assertEqualsWithDelta(1.45, $sheet->getCell('J12')->getValue(), 0.0001);
        self::assertEqualsWithDelta(2.1025, $sheet->getCell('K12')->getValue(), 0.00001);
        self::assertEqualsWithDelta(14.98, $sheet->getCell('L12')->getValue(), 0.00001);
        self::assertSame('Normal', $sheet->getCell('M12')->getValue());
        self::assertSame('Normal', $sheet->getCell('N12')->getValue());
        self::assertSame('Dewormed', $sheet->getCell('O12')->getValue());

        // not weighed: listed, numbered and aged, nothing measured
        self::assertSame(2, $sheet->getCell('A13')->getValue());
        self::assertSame('BAÑEZ, LEARNER, -', $sheet->getCell('C13')->getValue());
        self::assertSame(13, $sheet->getCell('H13')->getValue());
        foreach (['I', 'J', 'K', 'L', 'M', 'N'] as $column) {
            self::assertNull($sheet->getCell($column.'13')->getValue(), $column.'13');
        }
        self::assertNull($sheet->getCell('C14')->getValue());

        self::assertSame(1, $sheet->getCell('A63')->getValue());
        self::assertSame('CRUZ, ANA, -', $sheet->getCell('C63')->getValue());
        // Kinder age: measured and BMI shown, categories not computed
        self::assertEqualsWithDelta(18.0, $sheet->getCell('I63')->getValue(), 0.0001);
        self::assertNotNull($sheet->getCell('L63')->getValue());
        self::assertNull($sheet->getCell('M63')->getValue());
        self::assertNull($sheet->getCell('N63')->getValue());
        self::assertSame('Overweight', $sheet->getCell('M64')->getValue());
        self::assertSame('Tall', $sheet->getCell('N64')->getValue());
    }

    public function testSummary(): void
    {
        $report = $this->report();
        $sheet = $this->written($report);

        foreach ([118 => true, 119 => false, 120 => null] as $row => $male) {
            foreach (['C', 'E', 'F', 'G', 'H'] as $k => $column) {
                self::assertSame($report->summary->bmi(BmiCategory::cases()[$k], $male), $sheet->getCell($column.$row)->getValue(), $column.$row);
            }
            foreach (['J', 'K', 'L', 'M'] as $k => $column) {
                self::assertSame($report->summary->hfa(HfaCategory::cases()[$k], $male), $sheet->getCell($column.$row)->getValue(), $column.$row);
            }
        }
        self::assertSame(1, $sheet->getCell('F118')->getValue());
        self::assertSame(1, $sheet->getCell('I118')->getValue());
        self::assertSame(1, $sheet->getCell('I119')->getValue());
        // the Kinder-age learner is weighed but not counted
        self::assertSame(2, $sheet->getCell('I120')->getValue());
        self::assertSame(2, $sheet->getCell('N120')->getValue());
        self::assertSame(0, $sheet->getCell('C120')->getValue());
    }

    public function testSignatures(): void
    {
        $sheet = $this->written($this->report());

        self::assertSame('2025-08-20', Date::excelToDateTimeObject($sheet->getCell('A124')->getValue())->format('Y-m-d'));
        self::assertSame('DAVE SOSMEÑA', $sheet->getCell('E124')->getValue());
        self::assertSame('Adviser', $sheet->getCell('E125')->getValue());
        self::assertSame('Ma. Luz Cabrera', $sheet->getCell('I124')->getValue());
        self::assertSame('School Nurse', $sheet->getCell('I125')->getValue());
        self::assertSame('RENE PETANCIO', $sheet->getCell('M124')->getValue());
        self::assertSame('School Principal', $sheet->getCell('M125')->getValue());
    }

    public function testOverflowInsertsRows(): void
    {
        $males = [];
        for ($i = 1; $i <= 55; ++$i) {
            $males[] = $this->row(\sprintf('MALE, LEARNER %02d, -', $i), true, 40.0, 1.5, bmi: BmiCategory::Normal, hfa: HfaCategory::Normal);
        }
        $sheet = $this->written($this->report($males));

        for ($i = 1; $i <= 55; ++$i) {
            self::assertSame(\sprintf('MALE, LEARNER %02d, -', $i), $sheet->getCell('C'.(11 + $i))->getValue());
        }
        self::assertSame(55, $sheet->getCell('A66')->getValue());
        self::assertSame('FEMALE', $sheet->getCell('A67')->getValue());
        self::assertSame('CRUZ, ANA, -', $sheet->getCell('C68')->getValue());
        self::assertSame('MALE', $sheet->getCell('A123')->getValue());
        self::assertSame(55, $sheet->getCell('F123')->getValue());
        self::assertSame('2025-08-20', Date::excelToDateTimeObject($sheet->getCell('A129')->getValue())->format('Y-m-d'));
        self::assertSame('RENE PETANCIO', $sheet->getCell('M129')->getValue());
    }

    public function testNoFormulasOrRefErrors(): void
    {
        $book = $this->saved($this->report());

        self::assertSame(['Nutritional Status'], $book->getSheetNames());
        self::assertSame([], $book->getDefinedNames());
        foreach ($book->getActiveSheet()->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $value = (string) $cell->getValue();
                self::assertFalse(str_starts_with($value, '='), $cell->getCoordinate().' '.$value);
                self::assertStringNotContainsString('#REF!', $value, $cell->getCoordinate());
            }
        }
    }

    public function testTemplateHasNoComments(): void
    {
        $sheet = $this->written($this->report());

        self::assertSame([], $sheet->getComments());
    }

    public function testHeaderRowsAreTall(): void
    {
        $sheet = $this->written($this->report());

        self::assertSame(24.0, $sheet->getRowDimension(9)->getRowHeight());
        self::assertSame(30.0, $sheet->getRowDimension(10)->getRowHeight());
    }

    public function testFilename(): void
    {
        self::assertSame('SF8_303196_Grade 8-ARTISTIC_Baseline_2025-2026.xlsx', $this->writer()->filename($this->report()));
        $this->section->setName('A/B 1');
        self::assertSame('SF8_303196_Grade 8-A-B 1_Endline_2025-2026.xlsx', $this->writer()->filename($this->report(period: NutritionPeriod::Endline)));
    }
}
