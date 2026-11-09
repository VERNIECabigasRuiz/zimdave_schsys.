<?php

declare(strict_types=1);

namespace App\Announcements\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** One announcement. */
final readonly class GetAnnouncementCommand implements Query
{
    public function __construct(public int $id)
    {
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id];
    }
}
