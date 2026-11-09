<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** Card slips as a PDF: the adviser's class ($schoolId null) or a section of the school; one learner's when $enrollmentId is given. */
final readonly class GetCardSlipPdfCommand implements Query
{
    public function __construct(
        public int $actorId,
        public ?int $schoolId,
        public ?int $sectionId,
        public int $periodId,
        public ?int $enrollmentId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'sectionId' => $this->sectionId,
            'periodId' => $this->periodId,
            'enrollmentId' => $this->enrollmentId,
        ];
    }
}
