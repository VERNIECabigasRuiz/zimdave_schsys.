<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Enrolls the learner with this LRN in a section this school year: an existing learner (learnerId set) or a
 * new one (details set). Bound to the enroll form; on an adviser's page the section is their own.
 */
final class EnrollLearnerCommand implements UseCase
{
    public ?int $sectionId = null;

    public function __construct(
        public readonly int $actorId,
        public readonly int $schoolId,
        public readonly ?int $fixedSectionId,
        public readonly string $lrn,
        public readonly ?int $learnerId,
        #[Assert\Valid]
        public readonly ?LearnerDetails $details,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'fixedSectionId' => $this->fixedSectionId,
            'lrn' => $this->lrn,
            'learnerId' => $this->learnerId,
            'sectionId' => $this->sectionId,
        ];
    }
}
