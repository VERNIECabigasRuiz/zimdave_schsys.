<?php

declare(strict_types=1);

namespace App\Access\Application\Api;

use App\Access\Domain\Model\User;

/**
 * The accounts other modules may read.
 */
interface AccountDirectoryInterface
{
    public function findById(int $id): ?User;
}
