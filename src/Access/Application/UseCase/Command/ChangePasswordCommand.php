<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The signed-in user sets a new password (the form has already checked the current one). */
final readonly class ChangePasswordCommand implements UseCase
{
    public function __construct(
        public int $userId,
        #[\SensitiveParameter]
        public string $newPassword,
    ) {
    }

    public function toLogArray(): array
    {
        return ['userId' => $this->userId];
    }
}
