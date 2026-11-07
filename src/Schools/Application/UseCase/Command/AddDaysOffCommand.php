<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/** A principal or admin marks a day, or every weekday of a break, as a day off. Bound to the day off form. */
final class AddDaysOffCommand implements UseCase
{
    #[Assert\NotNull(message: 'Pick a date.')]
    public ?\DateTimeImmutable $from = null;

    public ?\DateTimeImmutable $to = null;

    #[Assert\NotBlank(message: 'Give a reason.')]
    #[Assert\Length(max: 100)]
    public ?string $reason = null;

    public function __construct(
        public readonly int $schoolId,
        public readonly int $userId,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'schoolId' => $this->schoolId,
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
        ];
    }
}
