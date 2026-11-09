<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The admin's school list for a school year (the current one when none is picked). */
final readonly class ListSchoolsCommand implements Query
{
    public function __construct(public ?int $schoolYearId = null)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolYearId' => $this->schoolYearId];
    }
}
