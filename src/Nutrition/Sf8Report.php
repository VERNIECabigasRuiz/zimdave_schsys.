<?php

namespace App\Nutrition;

use App\Entity\Section;
use App\Entity\Sf8Submission;

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
        return array_values(array_filter($this->rows, static fn (Sf8Row $r) => $r->entry->male));
    }

    /** @return list<Sf8Row> */
    public function females(): array
    {
        return array_values(array_filter($this->rows, static fn (Sf8Row $r) => !$r->entry->male));
    }
}
