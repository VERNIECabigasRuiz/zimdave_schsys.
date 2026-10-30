<?php

namespace App\Nutrition;

use App\Entity\Section;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fills DepEd's SF8 template (templates/forms/sf8-template.xlsx) with a report, values only. Rows are
 * added when a class has more than the template's 50 males or 50 females; every address below them
 * moves down by the rows added.
 */
class Sf8Writer
{
    private const TEMPLATE = '/templates/forms/sf8-template.xlsx';
    private const FIRST_MALE = 12;
    private const FEMALE_LABEL = 62;
    private const FIRST_FEMALE = 63;
    private const ROWS = 50;
    /** Below the grids, before any rows are added. */
    private const SUMMARY_MALE = 118;
    private const SUMMARY_FEMALE = 119;
    private const SUMMARY_TOTAL = 120;
    private const SIGNATURE = 124;
    private const POSITION = 125;
    private const BMI_COLUMNS = ['C', 'E', 'F', 'G', 'H'];
    private const BMI_TOTAL = 'I';
    private const HFA_COLUMNS = ['J', 'K', 'L', 'M'];
    private const HFA_TOTAL = 'N';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function write(Sf8Report $report): Spreadsheet
    {
        $book = IOFactory::load($this->projectDir.self::TEMPLATE);
        $sheet = $book->getActiveSheet();
        $males = $report->males();
        $females = $report->females();

        $extraMale = max(0, \count($males) - self::ROWS);
        $this->addRows($sheet, self::FEMALE_LABEL, $extraMale);
        $firstFemale = self::FIRST_FEMALE + $extraMale;
        $extraFemale = max(0, \count($females) - self::ROWS);
        $this->addRows($sheet, $firstFemale + self::ROWS, $extraFemale);
        $shift = $extraMale + $extraFemale;

        // the males fill their own pages, the females start a new one
        $sheet->setBreak('A'.(self::FEMALE_LABEL - 1), Worksheet::BREAK_NONE);
        $sheet->setBreak('A'.(self::FEMALE_LABEL - 1 + $extraMale), Worksheet::BREAK_ROW);
        // the template prints the Remarks column on pages of its own
        $sheet->getPageSetup()->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);

        $this->writeHeader($sheet, $report);
        $this->writeRows($sheet, $males, self::FIRST_MALE);
        $this->writeRows($sheet, $females, $firstFemale);
        $this->writeSummary($sheet, $report->summary, $shift);
        $this->writeSignatures($sheet, $report, $shift);

        $sheet->setSelectedCell('A1');

        return $book;
    }

    /** "SF8_303196_Grade 8-ARTISTIC_Baseline_2025-2026.xlsx"; "/", "\" and "%" in a section name become "-". */
    public function filename(Sf8Report $report): string
    {
        $section = $report->section;

        return \sprintf(
            'SF8_%s_%s-%s_%s_%s.xlsx',
            $section->getSchool()->getSchoolId(),
            $section->getGradeLevel()?->label() ?? '',
            preg_replace('/[\/\\\\%]/', '-', self::sectionName($section)),
            $report->period->label(),
            self::schoolYear($section),
        );
    }

    private function writeHeader(Worksheet $sheet, Sf8Report $report): void
    {
        $section = $report->section;
        $school = $section->getSchool();

        $sheet->setCellValue('A4', '(For All Grade Levels) — '.$report->period->label());
        $sheet->setCellValue('E5', $school->getName());
        $sheet->setCellValue('I5', $school->getDistrict());
        $sheet->setCellValue('L5', $school->getDivision());
        $sheet->setCellValue('O5', $school->getRegion());
        $sheet->setCellValueExplicit('C7', $school->getSchoolId(), 's');
        $sheet->setCellValue('F7', $section->getGradeLevel()?->label());
        $sheet->setCellValue('H7', self::sectionName($section));
        $track = $section->getTrack();
        $sheet->setCellValue('L7', null === $track ? 'N/A' : $track->label().(null !== $section->getStrand() ? ' / '.$section->getStrand() : ''));
        $sheet->setCellValue('O7', self::schoolYear($section));
        foreach (['E5', 'I5', 'L5', 'O5', 'F7', 'H7', 'L7'] as $cell) {
            $sheet->getStyle($cell)->getAlignment()->setShrinkToFit(true);
        }
    }

    private function addRows(Worksheet $sheet, int $before, int $count): void
    {
        if ($count <= 0) {
            return;
        }
        // copies the style of the learner row above
        $sheet->insertNewRowBefore($before, $count);
        for ($row = $before; $row < $before + $count; ++$row) {
            $sheet->mergeCells('C'.$row.':F'.$row);
        }
    }

    /** @param list<Sf8Row> $rows */
    private function writeRows(Worksheet $sheet, array $rows, int $first): void
    {
        foreach ($rows as $n => $learner) {
            $row = $first + $n;
            $result = $learner->result;
            $sheet->setCellValue('A'.$row, $n + 1);
            if (null !== $learner->lrn) {
                $sheet->setCellValueExplicit('B'.$row, $learner->lrn, 's');
            }
            $sheet->setCellValue('C'.$row, $learner->entry->name);
            if (null !== $learner->birthDate) {
                $sheet->setCellValue('G'.$row, Date::PHPToExcel($learner->birthDate));
            }
            $sheet->setCellValue('H'.$row, $result->ageYears());
            if ($learner->weighed) {
                $sheet->setCellValue('I'.$row, $learner->weightKg);
                $sheet->setCellValue('J'.$row, $learner->heightM);
                $sheet->setCellValue('K'.$row, null === $learner->heightM ? null : round($learner->heightM ** 2, 4));
            }
            $sheet->setCellValue('L'.$row, null === $result->bmi ? null : round($result->bmi, 2));
            $sheet->setCellValue('M'.$row, $result->bmiCategory?->label());
            $sheet->setCellValue('N'.$row, $result->hfaCategory?->label());
            $sheet->setCellValue('O'.$row, $learner->remarks);
        }
        if ([] === $rows) {
            return;
        }
        $last = $first + \count($rows) - 1;
        $sheet->getStyle('B'.$first.':B'.$last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C'.$first.':C'.$last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1)->setShrinkToFit(true);
        $sheet->getStyle('G'.$first.':G'.$last)->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        $sheet->getStyle('H'.$first.':H'.$last)->getNumberFormat()->setFormatCode('0');
        $sheet->getStyle('K'.$first.':K'.$last)->getNumberFormat()->setFormatCode('0.0000');
        $sheet->getStyle('M'.$first.':N'.$last)->getAlignment()->setShrinkToFit(true);
        $sheet->getStyle('O'.$first.':O'.$last)->getAlignment()->setShrinkToFit(true);
    }

    private function writeSummary(Worksheet $sheet, Sf8Summary $summary, int $shift): void
    {
        foreach ([self::SUMMARY_MALE => true, self::SUMMARY_FEMALE => false, self::SUMMARY_TOTAL => null] as $row => $male) {
            $row += $shift;
            $total = 0;
            foreach (BmiCategory::cases() as $k => $category) {
                $sheet->setCellValue(self::BMI_COLUMNS[$k].$row, $count = $summary->bmi($category, $male));
                $total += $count;
            }
            $sheet->setCellValue(self::BMI_TOTAL.$row, $total);
            $total = 0;
            foreach (HfaCategory::cases() as $k => $category) {
                $sheet->setCellValue(self::HFA_COLUMNS[$k].$row, $count = $summary->hfa($category, $male));
                $total += $count;
            }
            $sheet->setCellValue(self::HFA_TOTAL.$row, $total);
        }
    }

    private function writeSignatures(Worksheet $sheet, Sf8Report $report, int $shift): void
    {
        $name = self::SIGNATURE + $shift;
        $position = self::POSITION + $shift;
        // without a saved date the report's weighedOn is only today, so the date of weighing stays blank
        if ($report->dateSet) {
            $sheet->setCellValue('A'.$name, Date::PHPToExcel($report->weighedOn));
            $sheet->getStyle('A'.$name)->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        }
        $sheet->setCellValue('E'.$name, $report->adviserName);
        $sheet->setCellValue('E'.$position, 'Adviser');
        $sheet->setCellValue('I'.$name, $report->certifierName);
        $sheet->setCellValue('I'.$position, $report->certifierPosition);
        $sheet->setCellValue('M'.$name, $report->principalName);
        $sheet->setCellValue('M'.$position, $report->principalPosition);
        $sheet->getStyle('A'.$name.':O'.$position)->getAlignment()->setShrinkToFit(true);
    }

    private static function sectionName(Section $section): string
    {
        return mb_strtoupper($section->getName());
    }

    /** "2025-2026" */
    private static function schoolYear(Section $section): string
    {
        $schoolYear = $section->getSchoolYear();
        $start = (int) ($schoolYear->getStartDate()?->format('Y') ?? substr($schoolYear->getName(), 0, 4));

        return $start.'-'.($start + 1);
    }
}
