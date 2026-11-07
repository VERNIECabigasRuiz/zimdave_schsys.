<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The school a principal heads this school year, for its profile page. */
final readonly class GetSchoolProfileCommand implements Query
{
    public function __construct(public int $userId)
    {
    }

    public function toLogArray(): array
    {
        return ['userId' => $this->userId];
    }
}
