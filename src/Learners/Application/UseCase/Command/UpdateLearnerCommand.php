<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Command;

use Symfony\Component\Validator\Constraints as Assert;

/** Saves a learner's details; the LRN too when the account may change it. Bound to the details form. */
final class UpdateLearnerCommand extends LearnerChange
{
    public function __construct(
        int $actorId,
        int $schoolId,
        ?int $fixedSectionId,
        int $learnerId,
        #[Assert\Valid]
        public readonly LearnerDetails $details,
        public readonly bool $withLrn,
    ) {
        parent::__construct($actorId, $schoolId, $fixedSectionId, $learnerId);
    }

    public function toLogArray(): array
    {
        return parent::toLogArray() + $this->details->toLogArray() + ['withLrn' => $this->withLrn];
    }
}
