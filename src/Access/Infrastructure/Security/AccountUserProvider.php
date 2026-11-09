<?php

declare(strict_types=1);

namespace App\Access\Infrastructure\Security;

use App\Access\Domain\Model\User;
use App\Access\Infrastructure\Repository\DoctrineUserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads accounts for sign-in by username, and rehashes passwords when the hasher asks to.
 *
 * @implements UserProviderInterface<User>
 */
class AccountUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private readonly DoctrineUserRepository $users)
    {
    }

    public function loadUserByIdentifier(string $identifier): User
    {
        return $this->users->findOneBy(['username' => $identifier])
            ?? throw $this->notFound($identifier);
    }

    public function refreshUser(UserInterface $user): User
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $this->users->find($user->getId()) ?? throw $this->notFound($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        $this->users->upgradePassword($user, $newHashedPassword);
    }

    private function notFound(string $identifier): UserNotFoundException
    {
        $exception = new UserNotFoundException(\sprintf('User "%s" not found.', $identifier));
        $exception->setUserIdentifier($identifier);

        return $exception;
    }
}
