<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** What the class period form needs: the period (null for a new one) and the school year. */
final readonly class GetClassPeriodFormCommand implements Query
{
    public function __construct(public int $schoolId, public ?int $periodId = null)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId, 'periodId' => $this->periodId];
    }
}
