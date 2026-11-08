<?php

declare(strict_types=1);

namespace App\Attendance\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/**
 * The adviser hands the month's SF2 of their section ($sectionId, from the month page) to the principal;
 * $confirmMissing says everyone was present on school days without a roll call.
 */
final readonly class SubmitSf2Command implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $sectionId,
        public \DateTimeImmutable $month,
        public bool $confirmMissing,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'sectionId' => $this->sectionId,
            'month' => $this->month->format('Y-m'),
            'confirmMissing' => $this->confirmMissing,
        ];
    }
}
