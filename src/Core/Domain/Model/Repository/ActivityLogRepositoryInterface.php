<?php

declare(strict_types=1);

namespace App\Core\Domain\Model\Repository;

use App\Core\Domain\Model\ActivityLog;

interface ActivityLogRepositoryInterface
{
    /** Adds the entry; it is saved on the next flush. */
    public function record(ActivityLog $entry): void;

    /**
     * Newest first.
     *
     * @return list<ActivityLog>
     */
    public function findRecent(int $limit): array;
}
