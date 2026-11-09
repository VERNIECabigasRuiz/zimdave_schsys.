<?php

declare(strict_types=1);

namespace App\Announcements\Application\Transformer;

use App\Announcements\Domain\Model\Announcement;

/**
 * @phpstan-type AnnouncementData array{id: int, title: string, body: string, audience: string,
 *     audienceLabel: string, authorName: ?string, createdAt: \DateTimeImmutable}
 */
final class AnnouncementTransformer
{
    /** @return AnnouncementData */
    public static function toArray(Announcement $announcement): array
    {
        return [
            'id' => (int) $announcement->getId(),
            'title' => $announcement->getTitle(),
            'body' => $announcement->getBody(),
            'audience' => $announcement->getAudience(),
            'audienceLabel' => $announcement->getAudienceLabel(),
            'authorName' => $announcement->getAuthor()?->getFullName(),
            'createdAt' => $announcement->getCreatedAt(),
        ];
    }
}
