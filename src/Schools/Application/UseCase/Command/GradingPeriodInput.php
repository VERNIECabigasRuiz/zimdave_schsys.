<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

/** One row of the school year form's grading periods. */
final class GradingPeriodInput
{
    public ?\DateTimeImmutable $startDate = null;

    public ?\DateTimeImmutable $endDate = null;

    public function __construct(public readonly int $number)
    {
    }
}
