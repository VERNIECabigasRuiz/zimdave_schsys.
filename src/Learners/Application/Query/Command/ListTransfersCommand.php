<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** A school's transfers page: requests coming in or going out this school year ($tab "incoming" or "outgoing"). */
final readonly class ListTransfersCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public ?string $tab,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'schoolId' => $this->schoolId, 'tab' => $this->tab];
    }
}
