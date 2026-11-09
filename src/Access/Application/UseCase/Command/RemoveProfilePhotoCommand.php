<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The signed-in user removes their profile photo. */
final readonly class RemoveProfilePhotoCommand implements UseCase
{
    public function __construct(public int $userId)
    {
    }

    public function toLogArray(): array
    {
        return ['userId' => $this->userId];
    }
}
