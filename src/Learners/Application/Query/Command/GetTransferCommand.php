<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Command;

use App\Core\Application\Bus\Query;

/**
 * A transfer request to decide on from a school's transfers page: one leaving the school ($outgoing) or
 * coming into it, this school year. Gives the ids its decision locks.
 */
final readonly class GetTransferCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $transferId,
        public bool $outgoing,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'transferId' => $this->transferId,
            'outgoing' => $this->outgoing,
        ];
    }
}
