<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The principal deletes a row of an employee's service record. */
final readonly class DeleteServiceRowCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $employeeId,
        public int $entryId,
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
