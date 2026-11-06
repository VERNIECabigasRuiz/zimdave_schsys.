<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** An admin gives an account a new temporary password. */
final readonly class ResetAccountPasswordCommand implements UseCase
{
    public function __construct(
        public int $id,
        public string $actingUsername,
    ) {
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id];
    }
}
