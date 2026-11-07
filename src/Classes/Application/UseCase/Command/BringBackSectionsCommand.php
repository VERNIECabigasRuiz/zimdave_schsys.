<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** Copies last school year's sections that the current one doesn't have, without advisers. */
final readonly class BringBackSectionsCommand implements UseCase
{
    public function __construct(public int $schoolId)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId];
    }
}
