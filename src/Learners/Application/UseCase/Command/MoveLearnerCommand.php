<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Command;

/** Moves the learner to another section of the same school. */
final class MoveLearnerCommand extends LearnerChange
{
    public function __construct(
        int $actorId,
        int $schoolId,
        ?int $fixedSectionId,
        int $learnerId,
        public readonly int $sectionId,
    ) {
        parent::__construct($actorId, $schoolId, $fixedSectionId, $learnerId);
    }

    public function toLogArray(): array
    {
        return parent::toLogArray() + ['sectionId' => $this->sectionId];
    }
}
