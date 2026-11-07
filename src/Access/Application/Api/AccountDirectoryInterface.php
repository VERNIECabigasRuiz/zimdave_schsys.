<?php

declare(strict_types=1);

namespace App\Access\Application\Api;

use App\Access\Domain\Model\User;

/**
 * The accounts other modules may read and the account work they may ask for.
 */
interface AccountDirectoryInterface
{
    public function findById(int $id): ?User;

    /** The account with this username, trimmed and in any letter case. */
    public function findOneByUsername(string $username): ?User;

    /** Saved on the next flush. */
    public function register(User $account): void;

    /**
     * Gives the account a new temporary password it must change at the next sign-in.
     *
     * @return string the password, to show once
     */
    public function setTemporaryPassword(User $account): string;

    /** Whether another account than $exceptAccountId already has this username. */
    public function isUsernameTaken(string $username, ?int $exceptAccountId = null): bool;
}
