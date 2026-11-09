<?php

declare(strict_types=1);

namespace App\Nutrition\Domain\Service\Sf8;

use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Classes\Domain\Model\Section;
use App\Nutrition\Domain\Model\Sf8Submission;

/** Everything a section's SF8 (Report on Nutritional Status) shows for one weighing period. */
final readonly class Sf8Report
{
    /**
     * @param \DateTimeImmutable $weighedOn the assessment's date of weighing, or today when there is none
     * @param bool               $dateSet   whether $weighedOn comes from a saved assessment
     * @param list<Sf8Row>       $rows      males first, then females, each by name
     */
    public function __construct(
        public Section $section,
        public NutritionPeriod $period,
        public \DateTimeImmutable $weighedOn,
        public bool $dateSet,
        public array $rows,
        public Sf8Summary $summary,
        public Sf8Status $status,
        public ?Sf8Submission $submission,
        public ?string $adviserName,
        public ?string $principalName,
        public ?string $principalPosition,
        public ?string $certifierName,
        public ?string $certifierPosition,
    ) {
    }

    /** @return list<Sf8Row> */
    public function males(): array
    {
        return array_values(array_filter($this->rows, static fn (Sf8Row $r) => $r->male));
    }

    /** @return list<Sf8Row> */
    public function females(): array
    {
        return array_values(array_filter($this->rows, static fn (Sf8Row $r) => !$r->male));
    }

    /** "SF8_303196_Grade 8-ARTISTIC_Baseline_2025-2026.xlsx"; "/", "\" and "%" in a section name become "-". */
    public function fileName(): string
    {
        return \sprintf(
            'SF8_%s_%s-%s_%s_%s.xlsx',
            $this->section->getSchool()->getSchoolId(),
            $this->section->getGradeLevel()?->label() ?? '',
            preg_replace('/[\/\\\\%]/', '-', $this->sectionName()),
            $this->period->label(),
            $this->schoolYearLabel(),
        );
    }

    public function sectionName(): string
    {
        return mb_strtoupper($this->section->getName());
    }

    /** "2025-2026" */
    public function schoolYearLabel(): string
    {
        $schoolYear = $this->section->getSchoolYear();
        $start = (int) ($schoolYear->getStartDate()?->format('Y') ?? substr($schoolYear->getName(), 0, 4));

        return $start . '-' . ($start + 1);
    }
}
