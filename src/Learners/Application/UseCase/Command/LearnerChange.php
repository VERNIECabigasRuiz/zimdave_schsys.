<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/**
 * A change to one learner, made from a Learners page: the account making it, the page's school, the
 * adviser's own section on their pages (null on the school pages), and the learner.
 */
abstract class LearnerChange implements UseCase
{
    public function __construct(
        public readonly int $actorId,
        public readonly int $schoolId,
        public readonly ?int $fixedSectionId,
        public readonly int $learnerId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'fixedSectionId' => $this->fixedSectionId,
            'learnerId' => $this->learnerId,
        ];
    }
}
