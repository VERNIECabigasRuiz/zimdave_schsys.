<?php

namespace App\Tests\Data;

use App\Data\DepEdSubjects;
use App\Entity\GradeLevel;
use App\Entity\SchoolYear;
use PHPUnit\Framework\TestCase;

class DepEdSubjectsTest extends TestCase
{
    private static function sy(int $startYear): SchoolYear
    {
        return (new SchoolYear())->setName($startYear.'-'.($startYear + 1))->setStartDate(new \DateTimeImmutable($startYear.'-06-01'));
    }

    /** @return list<string> */
    private static function names(GradeLevel $grade, int $startYear = 2026): array
    {
        return array_column(DepEdSubjects::forGrade($grade, self::sy($startYear)), 'name');
    }

    /** @return list<array{code: string, name: string}> */
    private static function components(GradeLevel $grade, string $code, int $startYear = 2026): array
    {
        foreach (DepEdSubjects::forGrade($grade, self::sy($startYear)) as $subject) {
            if ($code === $subject['code']) {
                return $subject['components'];
            }
        }

        return [];
    }

    public function testGrade1(): void
    {
        $subjects = DepEdSubjects::forGrade(GradeLevel::Grade1, self::sy(2026));

        self::assertSame(['Language', 'Reading and Literacy', 'Mathematics', 'Makabansa', 'Good Manners and Right Conduct'], array_column($subjects, 'name'));
        self::assertSame(['LANG1', 'RL1', 'MATH1', 'MKB1', 'GMRC1'], array_column($subjects, 'code'));
    }

    public function testGrade3AddsScienceAfterMathematics(): void
    {
        self::assertSame(['Filipino', 'English', 'Mathematics', 'Science', 'Makabansa', 'Good Manners and Right Conduct'], self::names(GradeLevel::Grade3));
        self::assertSame(['Filipino', 'English', 'Mathematics', 'Makabansa', 'Good Manners and Right Conduct'], self::names(GradeLevel::Grade2));
    }

    public function testGrade4HasEppAndMapehComponents(): void
    {
        self::assertSame([
            'Filipino', 'English', 'Mathematics', 'Science', 'Araling Panlipunan', 'Good Manners and Right Conduct',
            'Edukasyong Pantahanan at Pangkabuhayan', 'MAPEH',
        ], self::names(GradeLevel::Grade4));
        self::assertSame([
            ['code' => 'MA4', 'name' => 'Music and Arts'],
            ['code' => 'PEH4', 'name' => 'Physical Education and Health'],
        ], self::components(GradeLevel::Grade4, 'MAPEH4'));
    }

    public function testGrade7HasValuesEducationAndTle(): void
    {
        $codes = array_column(DepEdSubjects::forGrade(GradeLevel::Grade7, self::sy(2026)), 'name', 'code');

        self::assertSame('Values Education', $codes['VE7']);
        self::assertSame('Technology and Livelihood Education', $codes['TLE7']);
        self::assertSame(['MA7', 'PEH7'], array_column(self::components(GradeLevel::Grade7, 'MAPEH7'), 'code'));
    }

    public function testGrade10UsesOldK12UntilSy2027(): void
    {
        $old = array_column(DepEdSubjects::forGrade(GradeLevel::Grade10, self::sy(2026)), 'name', 'code');
        self::assertSame('Edukasyon sa Pagpapakatao', $old['ESP10']);
        self::assertArrayNotHasKey('VE10', $old);
        self::assertSame(['MUS10', 'ARTS10', 'PE10', 'HLTH10'], array_column(self::components(GradeLevel::Grade10, 'MAPEH10'), 'code'));

        $matatag = array_column(DepEdSubjects::forGrade(GradeLevel::Grade10, self::sy(2027)), 'name', 'code');
        self::assertSame('Values Education', $matatag['VE10']);
        self::assertArrayNotHasKey('ESP10', $matatag);
        self::assertSame(['MA10', 'PEH10'], array_column(self::components(GradeLevel::Grade10, 'MAPEH10', 2027), 'code'));
    }

    public function testNoPresetForKinderAndSeniorHigh(): void
    {
        foreach ([GradeLevel::Kinder, GradeLevel::Grade11, GradeLevel::Grade12] as $grade) {
            self::assertSame([], DepEdSubjects::forGrade($grade, self::sy(2026)), $grade->label());
            self::assertFalse(DepEdSubjects::hasPreset($grade), $grade->label());
        }
        foreach (range(1, 10) as $value) {
            self::assertTrue(DepEdSubjects::hasPreset(GradeLevel::from($value)));
        }
    }

    public function testCodesUniqueWithinEachGrade(): void
    {
        foreach ([2026, 2027] as $year) {
            foreach (range(1, 10) as $value) {
                $codes = [];
                foreach (DepEdSubjects::forGrade(GradeLevel::from($value), self::sy($year)) as $subject) {
                    $codes[] = $subject['code'];
                    array_push($codes, ...array_column($subject['components'], 'code'));
                }
                self::assertNotEmpty($codes);
                self::assertSame(array_values(array_unique($codes)), $codes, "Grade $value, $year");
                foreach ($codes as $code) {
                    self::assertMatchesRegularExpression('/^[A-Z0-9-]+$/', $code);
                }
            }
        }
    }
}
