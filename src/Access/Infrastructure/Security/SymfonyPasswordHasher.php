<?php

declare(strict_types=1);

namespace App\Access\Infrastructure\Security;

use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Password\PasswordHasherInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SymfonyPasswordHasher implements PasswordHasherInterface
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function hash(User $user, string $plainPassword): string
    {
        return $this->hasher->hashPassword($user, $plainPassword);
    }

    public function isValid(User $user, string $plainPassword): bool
    {
        return $this->hasher->isPasswordValid($user, $plainPassword);
    }
}
