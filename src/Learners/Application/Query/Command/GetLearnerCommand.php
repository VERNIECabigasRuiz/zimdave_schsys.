<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Command;

use App\Core\Application\Bus\Query;

/**
 * A learner's page: details, this school year, history and transfers, and what the account may do. An
 * administrator picking a school to transfer to passes it as $toSchoolId.
 */
final readonly class GetLearnerCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public ?int $fixedSectionId,
        public int $learnerId,
        public ?int $toSchoolId = null,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'fixedSectionId' => $this->fixedSectionId,
            'learnerId' => $this->learnerId,
            'toSchoolId' => $this->toSchoolId,
        ];
    }
}
