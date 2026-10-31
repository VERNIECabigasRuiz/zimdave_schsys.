<?php

namespace App\CardSlip;

/** One learner's card slip for a grading term, ready to print. */
final readonly class CardSlip
{
    /**
     * @param list<CardSlipLine> $lines          top-level subjects in report-card order
     * @param list<string>       $comments       rendered comment texts, the typed one last
     * @param list<string>       $suggestedCodes what CardSlipSuggester proposes for this learner
     * @param list<string>       $chosenCodes    the codes the comments come from (saved, else suggested)
     * @param bool               $commentsSaved  whether the adviser saved comments of their own (else the suggestions print)
     */
    public function __construct(
        public string $schoolName,
        public ?string $schoolAddress,
        public ?string $logoPath,
        public string $periodLabel,
        public string $schoolYear,
        public string $learnerName,
        public ?string $lrn,
        public string $gradeSection,
        public string $adviserName,
        public array $lines,
        public ?float $average,
        public ?string $descriptor,
        public ?AttendanceTally $attendance,
        public array $comments,
        public bool $draft,
        public int $enrollmentId,
        public array $suggestedCodes,
        public array $chosenCodes,
        public ?string $customText,
        public bool $commentsSaved = false,
    ) {
    }

    /** "86.00", or "Incomplete" while a subject has no grade. */
    public function averageText(): string
    {
        return null === $this->average ? 'Incomplete' : number_format($this->average, 2);
    }
}
