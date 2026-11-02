<?php

namespace App\Data;

use App\Entity\GradeLevel;
use App\Entity\SchoolYear;

/**
 * DepEd's standard subjects per grade level, in report-card order, from the MATATAG
 * Curriculum Phase 1 learning areas. Grade 10 joins MATATAG in S.Y. 2027-2028 and
 * keeps the K to 12 subjects before that. Valid from S.Y. 2026-2027 onward.
 *
 * Kindergarten (developmental domains) and Grades 11–12 (strengthened SHS, differs
 * by strand) have no preset; schools enter those by hand.
 */
final class DepEdSubjects
{
    private const MATATAG_GRADE10_FROM = 2027;

    private const GMRC = ['GMRC', 'Good Manners and Right Conduct'];
    private const MAPEH_MATATAG = ['MAPEH', 'MAPEH', [['MA', 'Music and Arts'], ['PEH', 'Physical Education and Health']]];

    private const GRADE1 = [
        ['LANG', 'Language'], ['RL', 'Reading and Literacy'], ['MATH', 'Mathematics'], ['MKB', 'Makabansa'], self::GMRC,
    ];
    private const GRADE2 = [
        ['FIL', 'Filipino'], ['ENG', 'English'], ['MATH', 'Mathematics'], ['MKB', 'Makabansa'], self::GMRC,
    ];
    private const GRADE3 = [
        ['FIL', 'Filipino'], ['ENG', 'English'], ['MATH', 'Mathematics'], ['SCI', 'Science'], ['MKB', 'Makabansa'], self::GMRC,
    ];
    private const GRADES4TO6 = [
        ['FIL', 'Filipino'], ['ENG', 'English'], ['MATH', 'Mathematics'], ['SCI', 'Science'], ['AP', 'Araling Panlipunan'],
        self::GMRC, ['EPP', 'Edukasyong Pantahanan at Pangkabuhayan'], self::MAPEH_MATATAG,
    ];
    private const GRADES7TO10 = [
        ['FIL', 'Filipino'], ['ENG', 'English'], ['MATH', 'Mathematics'], ['SCI', 'Science'], ['AP', 'Araling Panlipunan'],
        ['VE', 'Values Education'], ['TLE', 'Technology and Livelihood Education'], self::MAPEH_MATATAG,
    ];
    private const GRADE10_K12 = [
        ['FIL', 'Filipino'], ['ENG', 'English'], ['MATH', 'Mathematics'], ['SCI', 'Science'], ['AP', 'Araling Panlipunan'],
        ['ESP', 'Edukasyon sa Pagpapakatao'], ['TLE', 'Technology and Livelihood Education'],
        ['MAPEH', 'MAPEH', [['MUS', 'Music'], ['ARTS', 'Arts'], ['PE', 'Physical Education'], ['HLTH', 'Health']]],
    ];

    public static function hasPreset(GradeLevel $grade): bool
    {
        return $grade->value >= 1 && $grade->value <= 10;
    }

    /**
     * Codes already carry the grade number (MATH7, MA7).
     *
     * @return list<array{code: string, name: string, components: list<array{code: string, name: string}>}>
     */
    public static function forGrade(GradeLevel $grade, SchoolYear $schoolYear): array
    {
        $list = match (true) {
            !self::hasPreset($grade) => [],
            GradeLevel::Grade1 === $grade => self::GRADE1,
            GradeLevel::Grade2 === $grade => self::GRADE2,
            GradeLevel::Grade3 === $grade => self::GRADE3,
            $grade->value <= 6 => self::GRADES4TO6,
            GradeLevel::Grade10 === $grade && (int) $schoolYear->getStartDate()?->format('Y') < self::MATATAG_GRADE10_FROM => self::GRADE10_K12,
            default => self::GRADES7TO10,
        };

        $suffix = (string) $grade->value;
        $withGrade = static fn (array $entry): array => ['code' => $entry[0].$suffix, 'name' => $entry[1]];

        return array_map(static fn (array $entry): array => $withGrade($entry) + [
            'components' => array_map($withGrade, $entry[2] ?? []),
        ], $list);
    }
}
