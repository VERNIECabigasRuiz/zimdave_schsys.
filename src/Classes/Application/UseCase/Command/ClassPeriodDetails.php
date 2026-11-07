<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/** The fields of a class period form. */
abstract class ClassPeriodDetails implements UseCase
{
    #[Assert\NotNull(message: 'Enter the start time.')]
    public ?\DateTimeImmutable $startsAt = null;

    #[Assert\NotNull(message: 'Enter the end time.')]
    #[Assert\Expression(
        expression: 'value === null or this.startsAt === null or value.format("H:i") > this.startsAt.format("H:i")',
        message: 'The end time must be after the start time.',
    )]
    public ?\DateTimeImmutable $endsAt = null;

    public bool $isBreak = false;

    #[Assert\Length(max: 40)]
    public ?string $breakLabel = null;

    public function __construct(public readonly int $schoolId)
    {
    }

    /** The period this form edits, or null for a new one. */
    public function periodId(): ?int
    {
        return null;
    }

    public function toLogArray(): array
    {
        return [
            'schoolId' => $this->schoolId,
            'startsAt' => $this->startsAt?->format('H:i'),
            'endsAt' => $this->endsAt?->format('H:i'),
            'breakLabel' => $this->breakLabel,
        ];
    }
}
