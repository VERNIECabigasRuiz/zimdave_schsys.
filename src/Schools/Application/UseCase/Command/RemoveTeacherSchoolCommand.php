<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** An admin takes a teacher account off its school this school year. */
final readonly class RemoveTeacherSchoolCommand implements UseCase
{
    public function __construct(public int $accountId)
    {
    }

    public function toLogArray(): array
    {
        return ['accountId' => $this->accountId];
    }
}
