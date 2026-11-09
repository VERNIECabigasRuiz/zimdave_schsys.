<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model\Repository;

use App\Access\Domain\Model\User;
use App\Staff\Domain\Model\PersonalDataSheet;

interface PersonalDataSheetRepositoryInterface
{
    /** The person's Personal Data Sheet, or null before they open their profile. */
    public function findFor(User $user): ?PersonalDataSheet;

    public function add(PersonalDataSheet $sheet): void;
}
