<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/**
 * A principal asks the school of the learner with this LRN to let them move into one of their sections.
 * Bound to the transfer request form; $learnerId is the learner the enroll page found.
 */
final class RequestTransferCommand implements UseCase
{
    public ?int $sectionId = null;

    public function __construct(
        public readonly int $actorId,
        public readonly int $schoolId,
        public readonly string $lrn,
        public readonly int $learnerId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'lrn' => $this->lrn,
            'learnerId' => $this->learnerId,
            'sectionId' => $this->sectionId,
        ];
    }
}
