<?php

namespace App\Attendance;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fills DepEd's SF2 template (templates/forms/sf2-template.xlsx) with a report. Rows are added
 * when a class has more learners than the template's 21 male and 25 female rows.
 */
class Sf2Writer
{
    private const TEMPLATE = '/templates/forms/sf2-template.xlsx';
    private const FIRST_ROW = 14;
    private const MALE_ROWS = 21;
    private const FEMALE_ROWS = 25;
    private const FIRST_DAY_COLUMN = 4; // D
    /** Rows of the summary box below the grid, as in the template: [male, female, total] in AH, AI, AJ. */
    private const SUMMARY_ROWS = ['enrolmentAtCutOff' => 66, 'lateEnrollment' => 68, 'registered' => 70, 'percentEnrolment' => 72, 'ada' => 74, 'percentAttendance' => 75, 'consecutiveAbsent' => 77, 'dropped' => 79, 'transferredOut' => 81, 'transferredIn' => 83];
    private const SHADE = 'FF808080';
    private const WHITE = 'FFFFFFFF';
    /** The angle of the half fills: the colour changes along the cell's "/" diagonal. */
    private const DEGREE = 45;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function write(Sf2Report $report): Spreadsheet
    {
        $book = IOFactory::load($this->projectDir.self::TEMPLATE);
        $sheet = $book->getActiveSheet();

        $extraMale = max(0, \count($report->male) - self::MALE_ROWS);
        $maleTotal = self::FIRST_ROW + self::MALE_ROWS + $extraMale;
        $this->addRows($sheet, self::FIRST_ROW + self::MALE_ROWS, $extraMale);
        $firstFemale = $maleTotal + 1;
        $extraFemale = max(0, \count($report->female) - self::FEMALE_ROWS);
        $femaleTotal = $firstFemale + self::FEMALE_ROWS + $extraFemale;
        $this->addRows($sheet, $firstFemale + self::FEMALE_ROWS, $extraFemale);
        $shift = $extraMale + $extraFemale;
        if ($shift > 0) {
            // the template prints on two pages at a fixed scale; added rows would push its foot to a third
            $sheet->getPageSetup()->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(2);
        }

        $sheet->setCellValueExplicit('C6', (string) $report->schoolId, 's');
        $sheet->setCellValue('K6', $report->schoolYear);
        $sheet->setCellValue('X6', $report->month->format('F'));
        $sheet->setCellValue('C8', $report->schoolName);
        $sheet->setCellValue('X8', $report->gradeLevel);
        $sheet->setCellValue('AC8', $report->section);

        foreach ($report->days as $i => $day) {
            $column = self::column($i);
            $sheet->setCellValue($column.'11', (int) $day->format('j'));
            $sheet->setCellValue($column.'12', $report->dayLetter($i));
        }
        // upright day letters read better than the template's sideways ones
        $sheet->getStyle('D12:AB12')->getAlignment()->setTextRotation(0)->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        $this->writeRows($sheet, $report->male, self::FIRST_ROW);
        $this->writeRows($sheet, $report->female, $firstFemale);
        $combined = $femaleTotal + 1;
        $this->writeTotals($sheet, $maleTotal, $report->malePresent, $report->male);
        $this->writeTotals($sheet, $femaleTotal, $report->femalePresent, $report->female);
        $this->writeTotals($sheet, $combined, array_map(static fn (?int $m, ?int $f) => null === $m ? null : $m + $f, $report->malePresent, $report->femalePresent), $report->rows());
        // the template's arrow shapes don't survive a save; arrows in the text instead
        foreach ([$maleTotal, $femaleTotal, $combined] as $row) {
            $sheet->setCellValue('A'.$row, '◄   '.trim((string) $sheet->getCell('A'.$row)->getValue()).'   ►');
        }

        $sheet->setCellValue('AB'.(65 + $shift), $report->month->format('F'));
        $sheet->getStyle('AB'.(65 + $shift).':AD'.(65 + $shift))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER_CONTINUOUS);
        $sheet->setCellValue('AG'.(64 + $shift), $report->summary->daysOfClasses);
        $sheet->getStyle('AG'.(64 + $shift))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        foreach (self::SUMMARY_ROWS as $figure => $row) {
            foreach (['AH', 'AI', 'AJ'] as $k => $column) {
                $value = $report->summary->{$figure}[$k] ?? null;
                if (null !== $value) {
                    $sheet->setCellValue($column.($row + $shift), $value);
                    // the template formats some of these as percentages (the values already are) and rotates one
                    $style = $sheet->getStyle($column.($row + $shift));
                    $style->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_GENERAL);
                    $style->getFont()->setSize(11)->setItalic(false);
                    $style->getAlignment()->setTextRotation(0)->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
                }
            }
        }

        foreach ([88 => $report->adviserName, 92 => $report->schoolHeadName] as $row => $name) {
            $sheet->setCellValue('AD'.($row + $shift), $name);
            $sheet->getStyle('AD'.($row + $shift).':AI'.($row + $shift))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER_CONTINUOUS)->setVertical(Alignment::VERTICAL_BOTTOM);
            $sheet->getStyle('AD'.($row + $shift))->getFont()->setBold(true);
            // the template's 12 pt row clips the tilde of a capital Ñ
            $sheet->getRowDimension($row + $shift)->setRowHeight(18);
        }

        $sheet->setSelectedCell('A1');

        return $book;
    }

    /** Writes the report as .xlsx to $path, with the tardy cells half shaded. */
    public function save(Sf2Report $report, string $path): void
    {
        IOFactory::createWriter($this->write($report), 'Xlsx')->save($path);
        $this->sharpenHalfFills($path);
    }

    private function addRows(Worksheet $sheet, int $before, int $count): void
    {
        if ($count <= 0) {
            return;
        }
        // copies the row above's style, diagonals included
        $sheet->insertNewRowBefore($before, $count);
        for ($row = $before; $row < $before + $count; ++$row) {
            $sheet->mergeCells('B'.$row.':C'.$row);
            $sheet->mergeCells('AE'.$row.':AJ'.$row);
        }
    }

    /** @param list<Sf2Row> $rows */
    private function writeRows(Worksheet $sheet, array $rows, int $first): void
    {
        foreach ($rows as $n => $learner) {
            $row = $first + $n;
            $sheet->setCellValue('A'.$row, $n + 1);
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->setCellValue('B'.$row, $learner->name);
            $sheet->getStyle('B'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setIndent(1);
            foreach ($learner->cells as $i => $cell) {
                $coordinate = self::column($i).$row;
                match ($cell) {
                    Sf2Row::ABSENT => $this->absent($sheet, $coordinate),
                    Sf2Row::LATE => $this->halfShade($sheet, $coordinate, upper: true),
                    Sf2Row::CUTTING => $this->halfShade($sheet, $coordinate, upper: false),
                    default => null,
                };
            }
            $sheet->setCellValue('AC'.$row, $learner->absences);
            $sheet->setCellValue('AD'.$row, $learner->tardies);
            $sheet->getStyle('AC'.$row.':AD'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            if (null !== $learner->remark) {
                $sheet->setCellValue('AE'.$row, $learner->remark);
                $sheet->getStyle('AE'.$row)->getAlignment()->setShrinkToFit(true);
            }
        }
    }

    /**
     * @param list<?int>   $present per day
     * @param list<Sf2Row> $rows
     */
    private function writeTotals(Worksheet $sheet, int $row, array $present, array $rows): void
    {
        foreach ($present as $i => $count) {
            if (null !== $count) {
                $sheet->setCellValue(self::column($i).$row, $count);
            }
        }
        $sheet->setCellValue('AC'.$row, array_sum(array_map(static fn (Sf2Row $r) => $r->absences, $rows)));
        $sheet->setCellValue('AD'.$row, array_sum(array_map(static fn (Sf2Row $r) => $r->tardies, $rows)));
        // the template's male total row has sideways text and an "00000" format
        $style = $sheet->getStyle('D'.$row.':AD'.$row);
        $style->getAlignment()->setTextRotation(0)->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $style->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_GENERAL);
    }

    private function absent(Worksheet $sheet, string $coordinate): void
    {
        $sheet->setCellValue($coordinate, 'x');
        $sheet->getStyle($coordinate)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle($coordinate)->getFont()->setBold(true);
    }

    /** Late shades the upper half of the cell, cutting classes the lower half (see sharpenHalfFills). */
    private function halfShade(Worksheet $sheet, string $coordinate, bool $upper): void
    {
        $sheet->getStyle($coordinate)->getFill()
            ->setFillType(Fill::FILL_GRADIENT_LINEAR)
            ->setRotation(self::DEGREE)
            ->setStartColor(new Color($upper ? self::SHADE : self::WHITE))
            ->setEndColor(new Color($upper ? self::WHITE : self::SHADE));
    }

    /**
     * PhpSpreadsheet writes a gradient with two stops, a soft fade. Doubling the stops at the
     * middle makes the colour change at once, so exactly half the cell is shaded.
     */
    private function sharpenHalfFills(string $path): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            throw new \RuntimeException('Could not reopen the SF2 file.');
        }
        try {
            $styles = (string) $zip->getFromName('xl/styles.xml');
            $styles = preg_replace_callback(
                '#<gradientFill type="linear" degree="'.self::DEGREE.'"><stop position="0"><color rgb="(\w+)"/></stop><stop position="1"><color rgb="(\w+)"/></stop></gradientFill>#',
                static fn (array $m) => '<gradientFill type="linear" degree="'.self::DEGREE.'">'
                    .'<stop position="0"><color rgb="'.$m[1].'"/></stop><stop position="0.5"><color rgb="'.$m[1].'"/></stop>'
                    .'<stop position="0.5001"><color rgb="'.$m[2].'"/></stop><stop position="1"><color rgb="'.$m[2].'"/></stop></gradientFill>',
                $styles,
            );
            $zip->addFromString('xl/styles.xml', $styles);
        } finally {
            $zip->close();
        }
    }

    private static function column(int $dayIndex): string
    {
        return Coordinate::stringFromColumnIndex(self::FIRST_DAY_COLUMN + $dayIndex);
    }
}
