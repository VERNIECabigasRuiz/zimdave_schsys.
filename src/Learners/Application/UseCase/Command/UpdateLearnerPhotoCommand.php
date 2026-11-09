<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Command;

/** Stores the uploaded image at $path as the learner's ID photo, or removes the photo when null. */
final class UpdateLearnerPhotoCommand extends LearnerChange
{
    public function __construct(
        int $actorId,
        int $schoolId,
        ?int $fixedSectionId,
        int $learnerId,
        public readonly ?string $path,
    ) {
        parent::__construct($actorId, $schoolId, $fixedSectionId, $learnerId);
    }

    public function toLogArray(): array
    {
        return parent::toLogArray() + ['photo' => null !== $this->path];
    }
}
