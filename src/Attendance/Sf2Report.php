<?php

namespace App\Attendance;

use App\Entity\Sf2Submission;

/** Everything a section's SF2 (Daily Attendance Report of Learners) shows for one month. */
final readonly class Sf2Report
{
    private const DAY_LETTERS = [1 => 'M', 2 => 'T', 3 => 'W', 4 => 'TH', 5 => 'F'];

    /**
     * @param list<\DateTimeImmutable> $days          the month's school days
     * @param list<bool>               $taken         whether each day has a roll call
     * @param list<Sf2Row>             $male
     * @param list<Sf2Row>             $female
     * @param list<?int>               $malePresent   present per day; null for a day that hasn't come
     * @param list<?int>               $femalePresent
     * @param list<\DateTimeImmutable> $missingDays   passed school days without a roll call
     * @param list<string>             $daysOff       "Aug 21 – Ninoy Aquino Day"
     */
    public function __construct(
        public ?string $schoolId,
        public string $schoolName,
        public string $schoolYear,
        public \DateTimeImmutable $month,
        public string $gradeLevel,
        public string $section,
        public array $days,
        public array $taken,
        public array $male,
        public array $female,
        public array $malePresent,
        public array $femalePresent,
        public Sf2Summary $summary,
        public ?string $adviserName,
        public ?string $schoolHeadName,
        public ?Sf2Submission $submission,
        public array $missingDays,
        public array $daysOff,
    ) {
    }

    public function dayLetter(int $i): string
    {
        return self::DAY_LETTERS[(int) $this->days[$i]->format('N')];
    }

    /** @return ?int present learners of the day, or null for a day that hasn't come */
    public function combinedPresent(int $i): ?int
    {
        return null === $this->malePresent[$i] ? null : $this->malePresent[$i] + $this->femalePresent[$i];
    }

    /** @return list<Sf2Row> */
    public function rows(): array
    {
        return [...$this->male, ...$this->female];
    }

    /** "SF2_2025_Grade 8 - ARTISTIC_August.xlsx" */
    public function fileName(): string
    {
        return \sprintf('SF2_%s_%s - %s_%s.xlsx', substr($this->schoolYear, 0, 4), $this->gradeLevel, preg_replace('/[\/\\\\%]/', '-', $this->section), $this->month->format('F'));
    }
}
