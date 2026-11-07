<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** A change to one teacher of a school. */
abstract readonly class TeacherChangeCommand implements UseCase
{
    public function __construct(
        public int $schoolId,
        public int $teacherId,
    ) {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId, 'teacherId' => $this->teacherId];
    }
}
