<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The signed-in user uploads a new profile photo (the uploaded file is at $sourcePath). */
final readonly class ChangeProfilePhotoCommand implements UseCase
{
    public function __construct(
        public int $userId,
        public string $sourcePath,
    ) {
    }

    public function toLogArray(): array
    {
        return ['userId' => $this->userId];
    }
}
