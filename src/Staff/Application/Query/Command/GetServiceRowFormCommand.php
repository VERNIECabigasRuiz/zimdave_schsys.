<?php

declare(strict_types=1);

namespace App\Staff\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The add or edit form of a service record row, filled in; no entryId means a new row drafted from the last. */
final readonly class GetServiceRowFormCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $employeeId,
        public ?int $entryId = null,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'employeeId' => $this->employeeId,
            'entryId' => $this->entryId,
        ];
    }
}
