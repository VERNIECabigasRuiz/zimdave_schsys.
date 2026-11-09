<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** A teacher account's school this school year, and the schools the admin may move them to. */
final readonly class GetTeacherSchoolCommand implements Query
{
    public function __construct(public int $accountId)
    {
    }

    public function toLogArray(): array
    {
        return ['accountId' => $this->accountId];
    }
}
