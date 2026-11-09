<?php

declare(strict_types=1);

namespace App\Announcements\Domain\Model\Repository;

use App\Announcements\Domain\Model\Announcement;

interface AnnouncementRepositoryInterface
{
    public function findById(int $id): ?Announcement;

    /** @return list<Announcement> newest first */
    public function findNewestFirst(): array;

    /** Adds the announcement; it is saved on the next flush. */
    public function add(Announcement $announcement): void;

    /** Removes the announcement on the next flush. */
    public function remove(Announcement $announcement): void;
}
