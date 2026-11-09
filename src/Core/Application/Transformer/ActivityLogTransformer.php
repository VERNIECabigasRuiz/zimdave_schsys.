<?php

declare(strict_types=1);

namespace App\Core\Application\Transformer;

use App\Core\Domain\Model\ActivityLog;

final class ActivityLogTransformer
{
    /** @return array{username: string, action: string, createdAt: \DateTimeImmutable} */
    public static function toArray(ActivityLog $entry): array
    {
        return [
            'username' => $entry->getUsername(),
            'action' => $entry->getAction(),
            'createdAt' => $entry->getCreatedAt(),
        ];
    }
}
