<?php

declare(strict_types=1);

namespace App\Access\Domain\Service\Password;

use App\Access\Domain\Model\User;

interface PasswordHasherInterface
{
    public function hash(User $user, string $plainPassword): string;

    public function isValid(User $user, string $plainPassword): bool;
}
