<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** What a principal's transfer request for the learner with this LRN needs: the learner, and the school's sections. */
final readonly class GetTransferRequestFormCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public string $lrn,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'schoolId' => $this->schoolId, 'lrn' => $this->lrn];
    }
}
