<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** A principal or admin brings last school year's teachers back, within the plan. */
final readonly class BringBackTeachersCommand implements UseCase
{
    public function __construct(public int $schoolId)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId];
    }
}
