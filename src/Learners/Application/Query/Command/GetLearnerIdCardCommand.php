<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** One learner's ID card as a PDF, e.g. to replace a lost one; an unknown $layout prints a sheet. */
final readonly class GetLearnerIdCardCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public ?int $fixedSectionId,
        public int $learnerId,
        public string $layout,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'fixedSectionId' => $this->fixedSectionId,
            'learnerId' => $this->learnerId,
            'layout' => $this->layout,
        ];
    }
}
