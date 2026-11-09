<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Command;

/**
 * An administrator moves the learner to a section of another school at once. The section's school comes
 * with it, so its lock can be taken first.
 */
final class TransferLearnerCommand extends LearnerChange
{
    public function __construct(
        int $actorId,
        int $schoolId,
        ?int $fixedSectionId,
        int $learnerId,
        public readonly int $sectionId,
        public readonly int $toSchoolId,
    ) {
        parent::__construct($actorId, $schoolId, $fixedSectionId, $learnerId);
    }

    public function toLogArray(): array
    {
        return parent::toLogArray() + ['sectionId' => $this->sectionId, 'toSchoolId' => $this->toSchoolId];
    }
}
