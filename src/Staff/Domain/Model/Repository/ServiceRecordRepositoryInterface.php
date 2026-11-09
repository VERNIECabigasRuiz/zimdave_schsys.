<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model\Repository;

use App\Access\Domain\Model\User;
use App\Staff\Domain\Model\ServiceRecord;

interface ServiceRecordRepositoryInterface
{
    /** The employee's record, or null before their BP number is first saved. */
    public function findFor(User $employee): ?ServiceRecord;

    public function add(ServiceRecord $record): void;
}
