<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/** An admin creates an account that must set a new password at first sign-in. Bound to the account form. */
final class CreateAccountCommand implements UseCase
{
    public ?string $role = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $fullName = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 50)]
    #[Assert\Regex(pattern: '/^[a-zA-Z0-9._-]+$/', message: 'Use only letters, numbers, dots, dashes and underscores.')]
    public string $username = '';

    public string $temporaryPassword = '';

    public function toLogArray(): array
    {
        return ['role' => $this->role, 'username' => $this->username];
    }
}
