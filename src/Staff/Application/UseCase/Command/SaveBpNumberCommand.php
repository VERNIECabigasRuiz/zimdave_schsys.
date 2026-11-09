<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The principal saves the GSIS BP number on an employee's service record; a blank one clears it. */
final readonly class SaveBpNumberCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $employeeId,
        public string $bpNumber,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'schoolId' => $this->schoolId, 'employeeId' => $this->employeeId];
    }
}
