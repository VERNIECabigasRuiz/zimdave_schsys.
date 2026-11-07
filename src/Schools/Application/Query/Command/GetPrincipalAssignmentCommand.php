<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** A school's principal for a school year, and the principals the admin may choose from. */
final readonly class GetPrincipalAssignmentCommand implements Query
{
    public function __construct(
        public int $schoolId,
        public ?int $schoolYearId = null,
    ) {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId, 'schoolYearId' => $this->schoolYearId];
    }
}
