<?php

declare(strict_types=1);

namespace App\Staff\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** An employee's service record page: the PDS header, what it misses, the BP number and the rows. */
final readonly class GetServiceRecordCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $employeeId,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'schoolId' => $this->schoolId, 'employeeId' => $this->employeeId];
    }
}
