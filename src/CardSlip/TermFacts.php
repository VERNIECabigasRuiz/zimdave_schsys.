<?php

namespace App\CardSlip;

final readonly class TermFacts
{
    /**
     * @param array<string, ?int> $grades top-level subject label => grade
     */
    public function __construct(
        public array $grades,
        public ?float $average,
        public ?float $previousAverage,
        public ?int $absences,
        public ?int $tardies,
        public int $schoolDays,
    ) {
    }

    /** @return list<string> */
    public function failingSubjects(): array
    {
        $failing = [];
        foreach ($this->grades as $label => $grade) {
            if (null !== $grade && $grade < 75) {
                $failing[] = (string) $label;
            }
        }

        return $failing;
    }

    public function isIncomplete(): bool
    {
        foreach ($this->grades as $grade) {
            if (null === $grade) {
                return true;
            }
        }

        return false;
    }
}
