<?php

declare(strict_types=1);

namespace App\Access\Application\Api;

use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Model\User;

final class AccountDirectory implements AccountDirectoryInterface
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    public function findById(int $id): ?User
    {
        return $this->users->findById($id);
    }
}
