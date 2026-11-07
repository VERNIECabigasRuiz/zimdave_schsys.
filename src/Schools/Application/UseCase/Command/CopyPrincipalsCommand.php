<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** An admin copies the previous school year's principals into a school year. */
final readonly class CopyPrincipalsCommand implements UseCase
{
    public function __construct(public ?int $schoolYearId = null)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolYearId' => $this->schoolYearId];
    }
}
