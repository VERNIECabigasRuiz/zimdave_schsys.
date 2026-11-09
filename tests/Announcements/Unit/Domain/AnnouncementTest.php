<?php

declare(strict_types=1);

namespace App\Tests\Announcements\Unit\Domain;

use App\Access\Domain\Model\User;
use App\Announcements\Domain\Model\Announcement;
use PHPUnit\Framework\TestCase;

final class AnnouncementTest extends TestCase
{
    public function testTrimsTitleAndBody(): void
    {
        // Execute
        $announcement = (new Announcement())->setTitle('  Grades due  ')->setBody("\n Friday \n");

        // Assert
        self::assertNull($announcement->getId());
        self::assertSame('Grades due', $announcement->getTitle());
        self::assertSame('Friday', $announcement->getBody());
    }

    public function testStartsForEveryoneWithItsCreationTime(): void
    {
        // Initialize
        $before = new \DateTimeImmutable();

        // Execute
        $announcement = new Announcement();

        // Assert
        self::assertSame(Announcement::AUDIENCE_ALL, $announcement->getAudience());
        self::assertGreaterThanOrEqual($before->getTimestamp(), $announcement->getCreatedAt()->getTimestamp());
        self::assertLessThanOrEqual(time(), $announcement->getCreatedAt()->getTimestamp());
    }

    public function testAudienceLabels(): void
    {
        // Initialize
        $announcement = new Announcement();
        $labels = [];

        // Execute
        foreach (['all', User::ROLE_PRINCIPAL, User::ROLE_TEACHER, User::ROLE_STUDENT, 'ROLE_OTHER'] as $audience) {
            $labels[] = $announcement->setAudience($audience)->getAudienceLabel();
        }

        // Assert
        self::assertSame(['Everyone', 'Principals', 'Teachers', 'Students', 'ROLE_OTHER'], $labels);
    }

    public function testAuthorCanBeSetAndCleared(): void
    {
        // Initialize
        $author = (new User())->setUsername('ana');
        $announcement = new Announcement();

        // Execute
        $withAuthor = $announcement->setAuthor($author)->getAuthor();
        $withoutAuthor = $announcement->setAuthor(null)->getAuthor();

        // Assert
        self::assertSame('ana', $withAuthor?->getUsername());
        self::assertNull($withoutAuthor);
    }
}
