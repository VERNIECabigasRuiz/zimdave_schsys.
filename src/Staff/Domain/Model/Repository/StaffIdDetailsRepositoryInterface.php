<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model\Repository;

use App\Access\Domain\Model\User;
use App\Staff\Domain\Model\StaffIdDetails;

interface StaffIdDetailsRepositoryInterface
{
    /** The person's saved ID details, or null before they first save them. */
    public function findFor(User $user): ?StaffIdDetails;

    public function add(StaffIdDetails $details): void;
}
