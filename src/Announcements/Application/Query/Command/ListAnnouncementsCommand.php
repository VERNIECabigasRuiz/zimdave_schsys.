<?php

declare(strict_types=1);

namespace App\Announcements\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** Every announcement, newest first. */
final readonly class ListAnnouncementsCommand implements Query
{
    public function toLogArray(): array
    {
        return [];
    }
}
