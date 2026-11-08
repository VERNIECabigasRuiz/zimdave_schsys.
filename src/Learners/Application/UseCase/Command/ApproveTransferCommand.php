<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Command;

/** The school being left lets the learner move; the receiving school's id comes with it so it can be locked first. */
final readonly class ApproveTransferCommand extends DecideTransferCommand
{
    public function __construct(int $actorId, int $schoolId, int $transferId, int $learnerId, public int $toSchoolId)
    {
        parent::__construct($actorId, $schoolId, $transferId, $learnerId);
    }

    public function toLogArray(): array
    {
        return parent::toLogArray() + ['toSchoolId' => $this->toSchoolId];
    }
}
