<?php

declare(strict_types=1);

namespace App\Announcements\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** An admin deletes an announcement. */
final readonly class DeleteAnnouncementCommand implements UseCase
{
    public function __construct(public int $id)
    {
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id];
    }
}
