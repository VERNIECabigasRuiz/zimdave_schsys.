<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** Whether the school's plan has a seat for another teacher this school year. */
final readonly class GetTeacherSeatCommand implements Query
{
    public function __construct(public int $schoolId)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId];
    }
}
