<?php

declare(strict_types=1);

namespace App\Access\Domain\Model\Repository;

use App\Access\Domain\Model\User;

interface UserRepositoryInterface
{
    public function findById(int $id): ?User;

    /** Matches the username however it was typed (case and surrounding spaces ignored). */
    public function findOneByUsername(string $username): ?User;

    /** Adds the account; it is saved on the next flush. */
    public function add(User $user): void;

    /**
     * Accounts matching the given filters, newest first.
     *
     * @param ?string $role   one of User::ROLE_LABELS keys
     * @param ?bool   $active true = active only, false = inactive only
     *
     * @return array{items: list<User>, total: int}
     */
    public function search(?string $query, ?string $role, ?bool $active, int $page, int $perPage): array;

    public function countAll(): int;

    public function countByRole(string $role): int;

    public function countInactive(): int;
}
