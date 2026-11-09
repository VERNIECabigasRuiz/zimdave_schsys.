<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model\Repository;

use App\Access\Domain\Model\User;
use App\Staff\Domain\Model\ServiceRecordEntry;

interface ServiceRecordEntryRepositoryInterface
{
    public function findById(int $id): ?ServiceRecordEntry;

    /** @return list<ServiceRecordEntry> the employee's rows, oldest first */
    public function findForEmployee(User $employee): array;

    /** The row that runs to the present, which holds the employee's current designation. */
    public function findCurrent(User $employee): ?ServiceRecordEntry;

    public function add(ServiceRecordEntry $entry): void;

    public function remove(ServiceRecordEntry $entry): void;
}
