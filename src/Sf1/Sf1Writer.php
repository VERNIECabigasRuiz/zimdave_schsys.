<?php

namespace App\Sf1;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lays out an Sf1Report like the LIS sheet school_form_1_ver2014.2.1.1: the same 47 columns,
 * merges and rows, on legal paper, landscape. Layout only; the data comes ready-made.
 */
class Sf1Writer
{
    /** Each field's first and last column (1-based) and width in characters, as on the LIS sheet */
    private const COLUMNS = [
        'lrn' => [1, 2, 14],
        'name' => [3, 6, 30],
        'sex' => [7, 7, 5],
        'birthDate' => [8, 9, 11],
        'age' => [10, 11, 7],
        'motherTongue' => [12, 13, 13],
        'ipGroup' => [14, 14, 9],
        'religion' => [15, 15, 12],
        'street' => [16, 17, 14],
        'barangay' => [18, 20, 16],
        'city' => [21, 22, 14],
        'province' => [23, 27, 12],
        'father' => [28, 31, 24],
        'mother' => [32, 36, 24],
        'guardianName' => [37, 40, 18],
        'guardianRelationship' => [41, 41, 12],
        'contactNumber' => [42, 43, 13],
        'learningModality' => [44, 44, 13],
        'remarks' => [45, 47, 20],
    ];

    /** Heading of each single field, merged over both heading rows */
    private const HEADINGS = [
        'lrn' => 'LRN',
        'name' => "NAME\n(Last Name, First Name, Middle Name)",
        'sex' => 'Sex (M/F)',
        'birthDate' => "BIRTH DATE\n(mm/dd/yyyy)",
        'age' => 'AGE as of 1st Friday June',
        'motherTongue' => 'MOTHER TONGUE (Grade 1 to 3 Only)',
        'ipGroup' => "IP\n(Ethnic Group)",
        'religion' => 'RELIGION',
        'contactNumber' => 'Contact Number of Parent or Guardian',
        'learningModality' => 'Learning Modality',
    ];

    /** Grouped headings: the group on the first heading row, its fields' sub-headings on the second */
    private const GROUPS = [
        ['ADDRESS', ['street' => 'House #/ Street/ Sitio/ Purok', 'barangay' => 'Barangay', 'city' => 'Municipality/ City', 'province' => 'Province']],
        ['PARENTS', ['father' => "Father's Name (Last Name, First Name, Middle Name)", 'mother' => "Mother's Maiden Name (Last Name, First Name, Middle Name)"]],
        ["GUARDIAN\n(if Not Parent)", ['guardianName' => 'Name', 'guardianRelationship' => 'Relationship']],
        ['REMARKS', ['remarks' => '(Please refer to the legend on last page)']],
    ];

    /** The legend's two halves, a line each: indicator, code, required information */
    private const LEGEND = [
        [['Transfered Out', 'T/O', 'Name of Public (P) Private (PR) School & Effectivity Date'], ['CCT Recipient', 'CCT', 'CCT Control/reference number & Effectivity Date']],
        [['Transfered In', 'T/I', 'Name of Public (P) Private (PR) School & Effectivity Date'], ['Balik Aral', 'B/A', 'Name of school last attended & Year']],
        [['Dropped', 'DRP', 'Reason and Effectivity Date'], ['Special Needs Education', 'SNED', 'Specify']],
        [['Late Enrollment', 'LE', 'Reason (Enrollment beyond 1st Friday of SY)'], ['Accelerated', 'ACL', 'Specify Level & Effectivity Date']],
    ];

    /** Where the legend's columns go: indicator, code, required information, for each half */
    private const LEGEND_COLUMNS = [['A1:A1', 'B1:C1', 'D1:H1'], ['I1:L1', 'M1:M1', 'N1:U1']];

    private const FIRST_DATA_ROW = 7;

    public function write(Sf1Report $report): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('school_form_1_ver2014.2.1.1');
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Arial')->setSize(8);

        $this->columns($sheet);
        $this->header($sheet, $report);
        $this->headings($sheet);
        $row = $this->rows($sheet, $report->male, self::FIRST_DATA_ROW);
        $row = $this->total($sheet, $row, \count($report->male), '<=== TOTAL MALE');
        $row = $this->rows($sheet, $report->female, $row);
        $row = $this->total($sheet, $row, \count($report->female), '<=== TOTAL FEMALE');
        $row = $this->total($sheet, $row, \count($report->male) + \count($report->female), '<=== COMBINED');
        $this->footer($sheet, $report, $row + 1);
        $this->pageSetup($sheet);

        return $spreadsheet;
    }

    private function columns(Worksheet $sheet): void
    {
        foreach (self::COLUMNS as [$from, $to, $width]) {
            for ($c = $from; $c <= $to; ++$c) {
                $sheet->getColumnDimensionByColumn($c)->setWidth($width / ($to - $from + 1));
            }
        }
        // as on LIS: A is wide enough for the legend's indicators, B narrow
        $sheet->getColumnDimension('A')->setWidth(13);
        $sheet->getColumnDimension('B')->setWidth(3);
    }

    private function header(Worksheet $sheet, Sf1Report $report): void
    {
        $sheet->setCellValue('A1', 'School Form 1 (SF 1) School Register');
        $sheet->setCellValue('A2', '(This replaces Form 1, Master List & STS Form 2-Family Background and Profile)');
        foreach (['A1:AU1', 'A2:AU2'] as $range) {
            $sheet->mergeCells($range);
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $labels = ['A3:E3' => 'School ID', 'Q3:S3' => 'Division', 'A4:E4' => 'School Name', 'Q4:S4' => 'School Year', 'Z4:AD4' => 'Grade Level', 'AJ4:AL4' => 'Section'];
        foreach ($labels as $range => $label) {
            $sheet->setCellValue(explode(':', $range)[0], $label);
            $sheet->mergeCells($range);
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $values = [
            'F3:I3' => $report->schoolId, 'K3:O3' => $report->region, 'T3:Y3' => $report->division,
            'F4:O4' => $report->schoolName, 'T4:X4' => $report->schoolYear, 'AE4:AH4' => $report->gradeLevel, 'AM4:AQ4' => $report->section,
        ];
        foreach ($values as $range => $value) {
            $first = explode(':', $range)[0];
            $sheet->setCellValueExplicit($first, $value, DataType::TYPE_STRING);
            $sheet->mergeCells($range);
            $sheet->getStyle($range)->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle($range)->getFont()->setBold(true);
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $sheet->getRowDimension(3)->setRowHeight(16);
        $sheet->getRowDimension(4)->setRowHeight(16);
    }

    private function headings(Worksheet $sheet): void
    {
        foreach (self::HEADINGS as $field => $text) {
            $this->put($sheet, $field, 5, $text, 6);
        }
        foreach (self::GROUPS as [$group, $fields]) {
            $from = self::COLUMNS[array_key_first($fields)][0];
            $to = self::COLUMNS[array_key_last($fields)][1];
            $sheet->setCellValue($this->cell($from, 5), $group);
            $sheet->mergeCells($this->cell($from, 5).':'.$this->cell($to, 5));
            foreach ($fields as $field => $text) {
                $this->put($sheet, $field, 6, $text);
            }
        }

        $range = 'A5:AU6';
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true)->setSize(7);
        $style->getAlignment()->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getRowDimension(5)->setRowHeight(30);
        $sheet->getRowDimension(6)->setRowHeight(42);
    }

    /** @param list<Sf1Row> $rows */
    private function rows(Worksheet $sheet, array $rows, int $row): int
    {
        foreach ($rows as $data) {
            foreach (array_keys(self::COLUMNS) as $field) {
                $this->put($sheet, $field, $row, $data->{$field});
            }
            $style = $sheet->getStyle('A'.$row.':AU'.$row);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $style->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
            // Excel doesn't grow merged cells to fit; two lines fit LIS's longest values
            $sheet->getRowDimension($row)->setRowHeight(24);
            ++$row;
        }

        return $row;
    }

    private function total(Worksheet $sheet, int $row, int $count, string $label): int
    {
        $this->put($sheet, 'lrn', $row, $count);
        $this->put($sheet, 'name', $row, $label);
        $style = $sheet->getStyle('A'.$row.':AU'.$row);
        $style->getFont()->setBold(true);
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        return $row + 1;
    }

    private function footer(Worksheet $sheet, Sf1Report $report, int $row): void
    {
        $sheet->setCellValue('A'.$row, 'List and Code of Indicators under REMARKS column');
        $sheet->getStyle('A'.$row)->getFont()->setBold(true);

        // the legend: a heading line, then one line per indicator in each half
        $head = $row + 1;
        foreach ([['Indicator', 'Code', 'Required Information'], ...self::LEGEND] as $i => $line) {
            $halves = 0 === $i ? [$line, $line] : $line;
            foreach ($halves as $half => $texts) {
                foreach (self::LEGEND_COLUMNS[$half] as $j => $columns) {
                    $range = str_replace('1', (string) ($head + $i), $columns);
                    $sheet->setCellValue(explode(':', $range)[0], $texts[$j]);
                    $this->merge($sheet, $range);
                }
            }
        }
        $legend = 'A'.$head.':U'.($head + 4);
        $sheet->getStyle($legend)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($legend)->getFont()->setSize(7);
        $sheet->getStyle($legend)->getAlignment()->setShrinkToFit(true);
        $sheet->getStyle('A'.$head.':U'.$head)->getFont()->setBold(true);

        // summary: learners registered at the beginning (BoSY) and left at the end (EoSY) of the school year
        $male = \count($report->male);
        $female = \count($report->female);
        $eosyTotal = null !== $report->eosyMale ? $report->eosyMale + ($report->eosyFemale ?? 0) : null;
        $summary = [['REGISTERED', 'BoSY', 'EoSY'], ['MALE', $male, $report->eosyMale], ['FEMALE', $female, $report->eosyFemale], ['TOTAL', $male + $female, $eosyTotal]];
        foreach ($summary as $i => $cells) {
            $r = $head + $i;
            foreach (['V%1$d:X%1$d', 'Y%1$d:Z%1$d', 'AA%1$d:AC%1$d'] as $j => $columns) {
                $range = \sprintf($columns, $r);
                if (null !== $cells[$j]) {
                    $sheet->setCellValue(explode(':', $range)[0], $cells[$j]);
                }
                $this->merge($sheet, $range);
            }
        }
        $table = 'V'.$head.':AC'.($head + 3);
        $sheet->getStyle($table)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($table)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('V'.$head.':AC'.$head)->getFont()->setBold(true)->setSize(7);

        // signatures: the adviser prepares, the school head certifies
        foreach ([['AE', 'AL', 'AI', 'Prepared by:', $report->adviserName, '(Signature of Adviser over Printed Name)'],
            ['AN', 'AU', 'AR', 'Certified Correct:', $report->schoolHeadName, '(Signature of School Head over Printed Name)']] as [$from, $to, $eosyCol, $title, $name, $caption]) {
            $sheet->setCellValue($from.$head, $title);
            foreach ([[$head + 2, $name], [$head + 3, $caption]] as [$r, $text]) {
                $range = $from.$r.':'.$to.$r;
                if (null !== $text) {
                    $sheet->setCellValue($from.$r, $text);
                }
                $sheet->mergeCells($range);
                $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            $sheet->getStyle($from.($head + 2).':'.$to.($head + 2))->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle($from.($head + 2))->getFont()->setBold(true);
            $sheet->getStyle($from.($head + 3))->getFont()->setSize(7);
            $sheet->setCellValue($from.($head + 5), 'BoSY Date:');
            $sheet->setCellValue($eosyCol.($head + 5), 'EoSY Date:');
        }

        $sheet->setCellValue('A'.($head + 7), 'Generated on: '.$report->generatedOn->format('l, F j, Y'));
        $sheet->getStyle('A'.($head + 7))->getFont()->setItalic(true);
    }

    private function merge(Worksheet $sheet, string $range): void
    {
        [$first, $last] = explode(':', $range);
        if ($first !== $last) {
            $sheet->mergeCells($range);
        }
    }

    private function pageSetup(Worksheet $sheet): void
    {
        $sheet->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_LEGAL)
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(5, 6);
        $sheet->getPageMargins()->setLeft(.25)->setRight(.25)->setTop(.4)->setBottom(.4);
    }

    /** Writes $value into the field's columns on $row, merged over them (and down to $toRow) */
    private function put(Worksheet $sheet, string $field, int $row, string|int|null $value, ?int $toRow = null): void
    {
        [$from, $to] = self::COLUMNS[$field];
        $first = $this->cell($from, $row);
        if (null !== $value) {
            // text, so LRNs and phone numbers keep their digits
            \is_int($value) ? $sheet->setCellValue($first, $value) : $sheet->setCellValueExplicit($first, $value, DataType::TYPE_STRING);
        }
        $this->merge($sheet, $first.':'.$this->cell($to, $toRow ?? $row));
    }

    private function cell(int $column, int $row): string
    {
        return Coordinate::stringFromColumnIndex($column).$row;
    }
}
