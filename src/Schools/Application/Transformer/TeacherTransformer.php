<?php

declare(strict_types=1);

namespace App\Schools\Application\Transformer;

use App\Access\Domain\Model\User;

/**
 * @phpstan-type TeacherData array{id: int, fullName: string, username: string, initials: string, active: bool,
 *     mustChangePassword: bool, lastLoginAt: ?\DateTimeImmutable}
 */
final class TeacherTransformer
{
    /** @return TeacherData */
    public static function toArray(User $teacher): array
    {
        return [
            'id' => (int) $teacher->getId(),
            'fullName' => $teacher->getFullName(),
            'username' => $teacher->getUsername(),
            'initials' => $teacher->getInitials(),
            'active' => $teacher->isActive(),
            'mustChangePassword' => $teacher->mustChangePassword(),
            'lastLoginAt' => $teacher->getLastLoginAt(),
        ];
    }
}
