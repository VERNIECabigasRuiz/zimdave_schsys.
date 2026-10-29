<?php

namespace App\Sf1;

/** Everything one section's SF1 prints; Sf1Writer lays it out. */
final readonly class Sf1Report
{
    /**
     * @param list<Sf1Row> $male
     * @param list<Sf1Row> $female
     * @param int|null     $eosyMale   learners left at the end of the school year, null until it has ended
     * @param string|null  $adviserName    upper case
     * @param string|null  $schoolHeadName upper case
     */
    public function __construct(
        public string $schoolId,
        public string $region,
        public string $division,
        public string $schoolName,
        public string $schoolYear,
        public string $gradeLevel,
        public string $section,
        public array $male,
        public array $female,
        public ?int $eosyMale,
        public ?int $eosyFemale,
        public ?string $adviserName,
        public ?string $schoolHeadName,
        public \DateTimeImmutable $generatedOn,
    ) {
    }

    /** "SF1_2025_Grade 8 - ARTISTIC.xls" */
    public function fileName(): string
    {
        return \sprintf('SF1_%s_%s - %s.xls', substr($this->schoolYear, 0, 4), $this->gradeLevel, $this->section);
    }
}
