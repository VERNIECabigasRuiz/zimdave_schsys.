<?php

declare(strict_types=1);

namespace App\Billing\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/** An admin edits a plan. Bound to the plan form, prefilled from GetPlanCommand. */
final class UpdatePlanCommand implements UseCase
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

    public function __construct(public readonly int $id)
    {
    }

    /** @param array{id: int, name: string, teachersPerSeat: ?int, studentsPerSeat: ?int, includesPrincipal: bool} $plan */
    public static function fromPlan(array $plan): self
    {
        $command = new self($plan['id']);
        $command->name = $plan['name'];
        $command->teachersPerSeat = $plan['teachersPerSeat'];
        $command->studentsPerSeat = $plan['studentsPerSeat'];
        $command->includesPrincipal = $plan['includesPrincipal'];

        return $command;
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
