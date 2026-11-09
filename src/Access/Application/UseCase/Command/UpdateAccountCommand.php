<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/** An admin edits an account. Bound to the account form, prefilled from GetAccountCommand. */
final class UpdateAccountCommand implements UseCase
{
    public ?string $role = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $fullName = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 50)]
    #[Assert\Regex(pattern: '/^[a-zA-Z0-9._-]+$/', message: 'Use only letters, numbers, dots, dashes and underscores.')]
    public string $username = '';

    public bool $active = true;

    public function __construct(public readonly int $id)
    {
    }

    /** @param array{id: int, role: ?string, fullName: string, username: string, active: bool} $account */
    public static function fromAccount(array $account): self
    {
        $command = new self($account['id']);
        $command->role = $account['role'];
        $command->fullName = $account['fullName'];
        $command->username = $account['username'];
        $command->active = $account['active'];

        return $command;
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id, 'role' => $this->role, 'username' => $this->username, 'active' => $this->active];
    }
}
