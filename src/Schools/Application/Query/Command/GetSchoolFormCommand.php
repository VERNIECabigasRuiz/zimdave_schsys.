<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** What the school form needs: the school (null for a new one) and the plans to pick from. */
final readonly class GetSchoolFormCommand implements Query
{
    public function __construct(public ?int $schoolId = null)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId];
    }
}
