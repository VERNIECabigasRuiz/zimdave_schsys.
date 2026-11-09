<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

final readonly class DeleteClassPeriodCommand implements UseCase
{
    public function __construct(public int $schoolId, public int $periodId)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId, 'periodId' => $this->periodId];
    }
}
