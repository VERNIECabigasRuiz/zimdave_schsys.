<?php

namespace App\Service;

use App\Entity\EducationLevel;
use App\Entity\PdsEducation;
use App\Entity\PdsEligibility;
use App\Entity\PdsTraining;
use App\Entity\PdsVoluntaryWork;
use App\Entity\PdsWorkExperience;
use App\Entity\PersonalDataSheet;

/**
 * Turns the PDS lists (III–VIII) into printable text cells: the rows that fit the
 * official grids, and continuation sheets (C5–C11) for the rest.
 */
final class PdsListGrids
{
    /** Rows on the official form. */
    public const ROWS = ['eligibility' => 7, 'work' => 24, 'training' => 17, 'voluntary' => 9, 'other' => 7];

    /**
     * Printed text lines each grid can take before its page would spill onto a
     * second sheet (measured on the A4 layout: a wrapped row takes more lines).
     */
    private const LINE_BUDGET = ['eligibility' => 12, 'work' => 48, 'training' => 34, 'voluntary' => 14, 'other' => 10];

    /** Column widths (% of the page) on the main grids, used to estimate wrapping. */
    private const COLUMN_WIDTHS = [
        'eligibility' => [29, 9, 14, 23, 11, 11],
        'work' => [9, 9, 20, 24, 9, 10, 10, 6],
        'training' => [35, 9, 9, 8, 13, 23],
        'voluntary' => [40, 10, 10, 9, 28],
        'other' => [30, 37, 33],
    ];

    /** Page 1 education grid: one row per level (label column included). */
    private const EDUCATION_WIDTHS = [13, 20, 20, 7, 7, 10, 8, 12];
    private const EDUCATION_LINE_BUDGET = 15;

    /** Characters per line for each % of page width at the grid font size. */
    private const CHARS_PER_PERCENT = 1.6;

    /** Continuation sheets, in the order they follow page 4. */
    private const CONTINUATIONS = [
        'training' => ['C5', 'LEARNING AND DEVELOPMENT (L&D) INTERVENTIONS/TRAINING PROGRAMS ATTENDED (Continuation)',
            ['TITLE OF LEARNING AND DEVELOPMENT INTERVENTIONS/TRAINING PROGRAMS (Write in full)' => 35, 'FROM' => 9, 'TO' => 9, 'NUMBER OF HOURS' => 8, 'Type of L&D (Managerial/ Supervisory/ Technical/etc)' => 14, 'CONDUCTED/ SPONSORED BY (Write in full)' => 25]],
        'work' => ['C6', 'WORK EXPERIENCE (Continuation)',
            ['FROM' => 9, 'TO' => 9, 'POSITION TITLE (Write in full/Do not abbreviate)' => 21, 'DEPARTMENT / AGENCY / OFFICE / COMPANY (Write in full/Do not abbreviate)' => 25, 'MONTHLY SALARY' => 9, 'SALARY/ JOB/ PAY GRADE (if applicable) & STEP INCREMENT (Format "00-0")' => 11, 'STATUS OF APPOINTMENT' => 10, "GOV'T SERVICE (Y/ N)" => 6]],
        'education' => ['C8', 'EDUCATIONAL BACKGROUND (Continuation)',
            ['LEVEL' => 13, 'NAME OF SCHOOL (Write in full)' => 21, 'BASIC EDUCATION/DEGREE/COURSE (Write in full)' => 21, 'FROM' => 7, 'TO' => 7, 'HIGHEST LEVEL/ UNITS EARNED (if not graduated)' => 11, 'YEAR GRADUATED' => 8, 'SCHOLARSHIP/ ACADEMIC HONORS RECEIVED' => 12]],
        'eligibility' => ['C9', 'CIVIL SERVICE ELIGIBILITY (Continuation)',
            ["CAREER SERVICE/ RA 1080 (BOARD/ BAR) UNDER SPECIAL LAWS/ CES/ CSEE BARANGAY ELIGIBILITY / DRIVER'S LICENSE" => 30, 'RATING (If Applicable)' => 9, 'DATE OF EXAMINATION / CONFERMENT' => 14, 'PLACE OF EXAMINATION / CONFERMENT' => 23, 'LICENSE NUMBER' => 12, 'Date of Validity' => 12]],
        'voluntary' => ['C10', 'VOLUNTARY WORK (Continuation)',
            ['NAME & ADDRESS OF ORGANIZATION (Write in full)' => 42, 'FROM' => 10, 'TO' => 10, 'NUMBER OF HOURS' => 9, 'POSITION / NATURE OF WORK' => 29]],
        'other' => ['C11', 'OTHER INFORMATION (Continuation)',
            ['31. SPECIAL SKILLS and HOBBIES' => 30, '32. NON-ACADEMIC DISTINCTIONS / RECOGNITION (Write in full)' => 37, '33. MEMBERSHIP IN ASSOCIATION/ORGANIZATION (Write in full)' => 33]],
    ];

    /**
     * @return array{
     *     main: array<string, list<list<string>>>,
     *     continuations: list<array{sheet: string, title: string, columns: array<string, int>, rows: list<list<string>>}>
     * }
     */
    public static function build(PersonalDataSheet $sheet): array
    {
        $all = [
            'eligibility' => array_map(self::eligibility(...), $sheet->sortedEligibilities()),
            'work' => array_map(self::work(...), $sheet->sortedWorkExperiences()),
            'training' => array_map(self::training(...), $sheet->sortedTrainings()),
            'voluntary' => array_map(self::voluntary(...), $sheet->sortedVoluntaryWorks()),
            'other' => self::other($sheet),
        ];

        $main = [];
        $more = [];
        $slots = [];
        foreach (self::ROWS as $key => $count) {
            [$main[$key], $more[$key], $slots[$key]] = self::fit($all[$key], $count, self::LINE_BUDGET[$key], self::COLUMN_WIDTHS[$key]);
        }
        [$main['education'], $more['education']] = self::education($sheet);

        $continuations = [];
        foreach (self::CONTINUATIONS as $key => [$code, $title, $columns]) {
            if ([] !== $more[$key]) {
                $continuations[] = ['sheet' => $code, 'title' => $title, 'columns' => $columns, 'rows' => $more[$key]];
            }
        }

        return ['main' => $main, 'slots' => $slots, 'continuations' => $continuations];
    }

    /**
     * Takes rows for the main grid while they fit its row count and line budget;
     * the rest go to the continuation sheet. Returns the rows, the overflow, and
     * how many rows (filled + blank) to print so the page keeps its size.
     *
     * @param list<list<string>> $rows
     * @param list<int>          $widths
     *
     * @return array{list<list<string>>, list<list<string>>, int}
     */
    private static function fit(array $rows, int $count, int $budget, array $widths): array
    {
        $used = 0;
        $taken = 0;
        foreach ($rows as $row) {
            $lines = self::lines($row, $widths);
            if ($taken >= $count || ($taken > 0 && $used + $lines > $budget)) {
                break;
            }
            $used += $lines;
            ++$taken;
        }
        $blanks = max(0, min($count - $taken, $budget - $used));

        return [\array_slice($rows, 0, $taken), \array_slice($rows, $taken), max(1, $taken + $blanks)];
    }

    /** Estimated printed lines of a row: its most-wrapped cell. */
    private static function lines(array $row, array $widths): int
    {
        $lines = 1;
        foreach ($row as $i => $cell) {
            $chars = max(4, (int) floor(($widths[$i] ?? 10) * self::CHARS_PER_PERCENT));
            $lines = max($lines, substr_count(wordwrap($cell, $chars, "\n", true), "\n") + 1);
        }

        return $lines;
    }

    /**
     * Page 1 has one row per level: the level's first school goes there, any other
     * school of that level goes to C8. A level with no school prints N/A.
     *
     * @return array{list<list<string>>, list<list<string>>}
     */
    private static function education(PersonalDataSheet $sheet): array
    {
        $byLevel = [];
        $more = [];
        foreach ($sheet->sortedEducations() as $row) {
            if (isset($byLevel[$row->level->value])) {
                $more[] = self::educationCells($row);
            } else {
                $byLevel[$row->level->value] = self::educationCells($row);
            }
        }

        // A level row too long for page 1 keeps its label there and moves to C8 in full
        $main = [];
        $moved = [];
        $used = 0;
        $levels = EducationLevel::cases();
        foreach ($levels as $i => $level) {
            $row = $byLevel[$level->value] ?? [$level->formLabel(), 'N/A', '', '', '', '', '', ''];
            $lines = self::lines($row, self::EDUCATION_WIDTHS);
            $stillToCome = \count($levels) - $i - 1;
            if ($used + $lines + $stillToCome > self::EDUCATION_LINE_BUDGET) {
                $moved[] = $row;
                $row = [$level->formLabel(), '(see sheet C8)', '', '', '', '', '', ''];
                $lines = 1;
            }
            $main[] = $row;
            $used += $lines;
        }

        return [$main, [...$moved, ...$more]];
    }

    /** @return list<string> */
    private static function educationCells(PdsEducation $e): array
    {
        return [$e->level->formLabel(), (string) $e->schoolName, (string) $e->course, (string) $e->yearFrom, (string) $e->yearTo, (string) $e->highestLevel, (string) $e->yearGraduated, (string) $e->honors];
    }

    /** @return list<string> */
    private static function eligibility(PdsEligibility $e): array
    {
        return [(string) $e->name, (string) $e->rating, self::date($e->examDate), (string) $e->examPlace, (string) $e->licenseNo, self::date($e->licenseValidUntil)];
    }

    /** @return list<string> */
    private static function work(PdsWorkExperience $w): array
    {
        return [
            self::date($w->dateFrom),
            $w->present ? 'PRESENT' : self::date($w->dateTo),
            (string) $w->positionTitle,
            (string) $w->office,
            null !== $w->monthlySalary && '' !== $w->monthlySalary ? number_format((float) $w->monthlySalary, 2) : '',
            (string) $w->salaryGrade,
            (string) $w->appointmentStatus,
            $w->governmentService ? 'Y' : 'N',
        ];
    }

    /** @return list<string> */
    private static function training(PdsTraining $t): array
    {
        return [(string) $t->title, self::date($t->dateFrom), self::date($t->dateTo), self::hours($t->hours), (string) $t->type, (string) $t->sponsor];
    }

    /** @return list<string> */
    private static function voluntary(PdsVoluntaryWork $v): array
    {
        return [(string) $v->organization, self::date($v->dateFrom), self::date($v->dateTo), self::hours($v->hours), (string) $v->nature];
    }

    /**
     * Items 31–33 side by side: row i holds the i-th entry of each list.
     *
     * @return list<list<string>>
     */
    private static function other(PersonalDataSheet $sheet): array
    {
        $rows = [];
        $count = max(\count($sheet->skills), \count($sheet->distinctions), \count($sheet->memberships));
        for ($i = 0; $i < $count; ++$i) {
            $rows[] = [$sheet->skills[$i] ?? '', $sheet->distinctions[$i] ?? '', $sheet->memberships[$i] ?? ''];
        }

        return $rows;
    }

    private static function date(?\DateTimeImmutable $date): string
    {
        return $date ? $date->format('d/m/Y') : '';
    }

    /** "24.00" → "24", "7.50" → "7.5". */
    private static function hours(?string $hours): string
    {
        if (null === $hours || '' === $hours) {
            return '';
        }

        return str_contains($hours, '.') ? rtrim(rtrim($hours, '0'), '.') : $hours;
    }
}
