<?php

declare(strict_types=1);

namespace App\Access\Infrastructure\Security;

use App\Access\Domain\Model\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Blocks sign-in for accounts an administrator has deactivated.
 */
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException(
                'Your account is disabled. Please contact your administrator.',
            );
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
