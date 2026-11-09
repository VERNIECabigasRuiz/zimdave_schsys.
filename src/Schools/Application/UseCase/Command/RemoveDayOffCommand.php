<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** A day off of the school becomes a school day again. */
final readonly class RemoveDayOffCommand implements UseCase
{
    public function __construct(
        public int $schoolId,
        public int $dayOffId,
    ) {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId, 'dayOffId' => $this->dayOffId];
    }
}
