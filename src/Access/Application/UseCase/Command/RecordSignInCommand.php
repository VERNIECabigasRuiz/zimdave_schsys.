<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The account has just signed in. */
final readonly class RecordSignInCommand implements UseCase
{
    public function __construct(public int $userId)
    {
    }

    public function toLogArray(): array
    {
        return ['userId' => $this->userId];
    }
}
