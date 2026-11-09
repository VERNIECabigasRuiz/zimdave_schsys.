<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** An admin removes a school's principal for a school year. */
final readonly class RemovePrincipalCommand implements UseCase
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
