<?php

declare(strict_types=1);

namespace App\Access\Application\Api;

use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Password\PasswordHasherInterface;
use App\Access\Domain\Service\Password\TemporaryPasswordGenerator;

final class AccountDirectory implements AccountDirectoryInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly PasswordHasherInterface $hasher,
        private readonly TemporaryPasswordGenerator $passwordGenerator,
    ) {
    }

    public function findById(int $id): ?User
    {
        return $this->users->findById($id);
    }

    public function findOneByUsername(string $username): ?User
    {
        return $this->users->findOneByUsername($username);
    }

    public function register(User $account): void
    {
        $this->users->add($account);
    }

    public function setTemporaryPassword(User $account): string
    {
        $password = $this->passwordGenerator->generate();
        $account->setPassword($this->hasher->hash($account, $password));
        $account->setMustChangePassword(true);

        return $password;
    }

    public function isUsernameTaken(string $username, ?int $exceptAccountId = null): bool
    {
        $owner = '' !== trim($username) ? $this->users->findOneByUsername($username) : null;

        return null !== $owner && $owner->getId() !== $exceptAccountId;
    }

    public function accountCounts(): array
    {
        return [
            'accounts' => $this->users->countAll(),
            'principals' => $this->users->countByRole(User::ROLE_PRINCIPAL),
            'teachers' => $this->users->countByRole(User::ROLE_TEACHER),
            'students' => $this->users->countByRole(User::ROLE_STUDENT),
            'inactive' => $this->users->countInactive(),
        ];
    }
}
