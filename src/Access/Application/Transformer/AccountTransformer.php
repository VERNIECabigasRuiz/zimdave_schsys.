<?php

declare(strict_types=1);

namespace App\Access\Application\Transformer;

use App\Access\Domain\Model\User;

/**
 * @phpstan-type Account array{id: int, username: string, fullName: string, role: ?string, roleLabel: string,
 *     initials: string, active: bool, mustChangePassword: bool, lastLoginAt: ?\DateTimeImmutable, photoPath: ?string}
 */
final class AccountTransformer
{
    /** @return Account */
    public static function toArray(User $user): array
    {
        return [
            'id' => (int) $user->getId(),
            'username' => $user->getUsername(),
            'fullName' => $user->getFullName(),
            'role' => $user->getRole(),
            'roleLabel' => $user->getRoleLabel(),
            'initials' => $user->getInitials(),
            'active' => $user->isActive(),
            'mustChangePassword' => $user->mustChangePassword(),
            'lastLoginAt' => $user->getLastLoginAt(),
            'photoPath' => $user->getPhotoPath(),
        ];
    }
}
