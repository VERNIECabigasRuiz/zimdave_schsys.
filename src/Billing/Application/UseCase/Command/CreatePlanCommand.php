<?php

declare(strict_types=1);

namespace App\Billing\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/** An admin creates a plan. Bound to the plan form. */
final class CreatePlanCommand implements UseCase
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    public string $name = '';

    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $teachersPerSeat = null;

    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $studentsPerSeat = null;

    public bool $includesPrincipal = true;

    public function toLogArray(): array
    {
        return ['name' => $this->name];
    }
}
