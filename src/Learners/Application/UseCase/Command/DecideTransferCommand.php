<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** A decision on a pending transfer request, made from $schoolId's transfers page. */
abstract readonly class DecideTransferCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $transferId,
        public int $learnerId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'transferId' => $this->transferId,
            'learnerId' => $this->learnerId,
        ];
    }
}
